<?php
$host = getenv('DB_HOST') ?: "mysql";
$user = getenv('DB_USER') ?: "username";
$password = getenv('DB_PASSWORD') ?: "password";
$database = getenv('DB_NAME') ?: "countries";

$maxRetries = 10;
$retryDelay = 2;
$pdo = null;

for ($i = 1; $i <= $maxRetries; $i++) {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "✅ Connected to database successfully.\n";
        break;
    } catch (PDOException $e) {
        if ($i === $maxRetries) {
            die("DB ERROR: " . $e->getMessage() . "\n");
        }
        echo "⏳ Waiting for database connection (attempt $i/$maxRetries)...\n";
        sleep($retryDelay);
    }
}

try {
    // === Create tables ===
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS characters (
            id INT PRIMARY KEY,
            name VARCHAR(255),
            species VARCHAR(100),
            status VARCHAR(100),
            gender VARCHAR(50),
            origin VARCHAR(255),
            location VARCHAR(255),
            image TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS episodes (
            id INT PRIMARY KEY,
            name VARCHAR(255),
            code VARCHAR(20),
            air_date VARCHAR(100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS character_episode (
            character_id INT,
            episode_id INT,
            PRIMARY KEY (character_id, episode_id),
            FOREIGN KEY (character_id) REFERENCES characters(id) ON DELETE CASCADE,
            FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS locations (
            id INT PRIMARY KEY,
            name VARCHAR(255),
            type VARCHAR(100),
            dimension VARCHAR(255)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (PDOException $e) {
    die("DB TABLE CREATION ERROR: " . $e->getMessage() . "\n");
}

function graphql_query($query)
{
    $ch = curl_init('https://rickandmortyapi.com/graphql');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Import an optional, verified JSON file for seasons not covered by the
 * official API. The expected shape is documented in
 * supplemental_data.example.json.
 */
function import_supplemental_data(PDO $pdo, string $file): void
{
    if (!is_file($file)) {
        return;
    }

    $payload = json_decode((string) file_get_contents($file), true);
    if (!is_array($payload)) {
        throw new RuntimeException("Invalid supplemental JSON: $file");
    }

    $pdo->beginTransaction();
    try {
        foreach (($payload['episodes'] ?? []) as $ep) {
            if (!isset($ep['id'], $ep['name'], $ep['episode'])) {
                throw new RuntimeException('Each supplemental episode needs id, name and episode.');
            }

            $pdo->prepare("
                INSERT INTO episodes (id, name, code, air_date)
                VALUES (:id, :name, :code, :air_date)
                ON DUPLICATE KEY UPDATE
                  name = VALUES(name), code = VALUES(code), air_date = VALUES(air_date)
            ")->execute([
                ':id' => $ep['id'],
                ':name' => $ep['name'],
                ':code' => $ep['episode'],
                ':air_date' => $ep['air_date'] ?? null,
            ]);

        }

        foreach (($payload['characters'] ?? []) as $char) {
            if (!isset($char['id'], $char['name'])) {
                throw new RuntimeException('Each supplemental character needs id and name.');
            }

            $pdo->prepare("
                INSERT INTO characters
                    (id, name, species, status, gender, origin, location, image)
                VALUES
                    (:id, :name, :species, :status, :gender, :origin, :location, :image)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name), species = VALUES(species),
                    status = VALUES(status), gender = VALUES(gender),
                    origin = VALUES(origin), location = VALUES(location),
                    image = VALUES(image)
            ")->execute([
                ':id' => $char['id'],
                ':name' => $char['name'],
                ':species' => $char['species'] ?? null,
                ':status' => $char['status'] ?? null,
                ':gender' => $char['gender'] ?? null,
                ':origin' => is_array($char['origin'] ?? null)
                    ? ($char['origin']['name'] ?? '') : ($char['origin'] ?? ''),
                ':location' => is_array($char['location'] ?? null)
                    ? ($char['location']['name'] ?? '') : ($char['location'] ?? ''),
                ':image' => $char['image'] ?? null,
            ]);

        }

        // Add relationships only after both parent tables have been populated.
        foreach (($payload['episodes'] ?? []) as $ep) {
            foreach (($ep['characters'] ?? []) as $characterId) {
                $characterId = is_array($characterId)
                    ? ($characterId['id'] ?? null)
                    : (int) $characterId;
                if ($characterId) {
                    $pdo->prepare("
                        INSERT IGNORE INTO character_episode (character_id, episode_id)
                        VALUES (:character_id, :episode_id)
                    ")->execute([
                        ':character_id' => $characterId,
                        ':episode_id' => $ep['id'],
                    ]);
                }
            }
        }

        foreach (($payload['characters'] ?? []) as $char) {
            foreach (($char['episode'] ?? []) as $episodeId) {
                $episodeId = is_array($episodeId)
                    ? ($episodeId['id'] ?? null)
                    : (int) $episodeId;
                if ($episodeId) {
                    $pdo->prepare("
                        INSERT IGNORE INTO character_episode (character_id, episode_id)
                        VALUES (:character_id, :episode_id)
                    ")->execute([
                        ':character_id' => $char['id'],
                        ':episode_id' => $episodeId,
                    ]);
                }
            }
        }

        foreach (($payload['locations'] ?? []) as $loc) {
            if (!isset($loc['id'], $loc['name'])) {
                continue;
            }
            $pdo->prepare("
                INSERT INTO locations (id, name, type, dimension)
                VALUES (:id, :name, :type, :dimension)
                ON DUPLICATE KEY UPDATE
                  name = VALUES(name), type = VALUES(type), dimension = VALUES(dimension)
            ")->execute([
                ':id' => $loc['id'],
                ':name' => $loc['name'],
                ':type' => $loc['type'] ?? null,
                ':dimension' => $loc['dimension'] ?? null,
            ]);
        }

        $pdo->commit();
        echo "✅ Imported supplemental data from $file\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function tvmaze_get(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'RickAndMortyImporter/1.0',
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status >= 400) {
        throw new RuntimeException("TVMaze request failed ($status): $url");
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid TVMaze response: $url");
    }
    return $data;
}

/**
 * TVMaze Importer:
 * Fetches episodes and characters for seasons not present in the official Rick and Morty API (Season 6+).
 * Preserves the exact same data format as the Rick and Morty API:
 * - Episodes: sequential IDs continuing from existing DB, formatted air_date ('F j, Y', e.g. 'September 4, 2022'), code ('S06E01')
 * - Characters: matches and reuses existing characters by name, assigns sequential IDs to new characters, sets unknown defaults
 * - Relations: links main cast and guest cast to episodes in character_episode
 */
function import_tvmaze(PDO $pdo, int $showId = 216): void
{
    echo "🎬 Starting TVMaze import for missing seasons...\n";

    // 1. Map existing characters
    $knownCharacters = [];
    foreach ($pdo->query("SELECT id, name FROM characters") as $row) {
        $knownCharacters[mb_strtolower(trim($row['name']))] = (int) $row['id'];
    }
    $maxCharId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM characters")->fetchColumn();

    // 2. Map existing episodes by code
    $knownEpisodes = [];
    foreach ($pdo->query("SELECT id, code FROM episodes") as $row) {
        $knownEpisodes[$row['code']] = (int) $row['id'];
    }
    $maxEpId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM episodes")->fetchColumn();

    // 3. Fetch main cast to associate with all new episodes
    $mainCharIds = [];
    try {
        $mainCast = tvmaze_get("https://api.tvmaze.com/shows/$showId/cast");
        foreach ($mainCast as $credit) {
            $char = $credit['character'] ?? [];
            if (!isset($char['name'])) continue;
            $nameKey = mb_strtolower(trim($char['name']));
            if (isset($knownCharacters[$nameKey])) {
                $mainCharIds[] = $knownCharacters[$nameKey];
            }
        }
    } catch (Exception $e) {
        echo "⚠️ TVMaze main cast fetch notice: " . $e->getMessage() . "\n";
    }

    // 4. Fetch all episodes
    $episodes = tvmaze_get("https://api.tvmaze.com/shows/$showId/episodes?specials=1");
    $importedEpCount = 0;
    $newCharsCount = 0;

    foreach ($episodes as $episode) {
        $season = (int) ($episode['season'] ?? 0);
        $number = (int) ($episode['number'] ?? 0);

        // Only import season 6 and later regular episodes
        if ($season < 6 || $number <= 0) {
            continue;
        }

        $code = sprintf('S%02dE%02d', $season, $number);

        if (isset($knownEpisodes[$code])) {
            $epId = $knownEpisodes[$code];
        } else {
            $maxEpId++;
            $epId = $maxEpId;
            $knownEpisodes[$code] = $epId;
        }

        // Format air_date exactly like Rick and Morty API (e.g. 'September 4, 2022')
        $airDate = !empty($episode['airdate']) ? date('F j, Y', strtotime($episode['airdate'])) : null;

        $pdo->prepare("
            INSERT INTO episodes (id, name, code, air_date)
            VALUES (:id, :name, :code, :air_date)
            ON DUPLICATE KEY UPDATE
              name = VALUES(name), code = VALUES(code), air_date = VALUES(air_date)
        ")->execute([
            ':id' => $epId,
            ':name' => $episode['name'],
            ':code' => $code,
            ':air_date' => $airDate,
        ]);
        $importedEpCount++;

        // Link series main cast (Rick, Morty, etc.) to this episode
        foreach ($mainCharIds as $mainId) {
            $pdo->prepare("
                INSERT IGNORE INTO character_episode (character_id, episode_id)
                VALUES (:character_id, :episode_id)
            ")->execute([
                ':character_id' => $mainId,
                ':episode_id' => $epId,
            ]);
        }

        // Guest cast gives episode-specific character credits
        try {
            $guestCast = tvmaze_get("https://api.tvmaze.com/episodes/{$episode['id']}/guestcast");
            foreach ($guestCast as $credit) {
                $character = $credit['character'] ?? [];
                if (!isset($character['name']) || trim($character['name']) === '') {
                    continue;
                }

                $charName = trim($character['name']);
                $nameKey = mb_strtolower($charName);

                if (isset($knownCharacters[$nameKey])) {
                    $characterId = $knownCharacters[$nameKey];
                } else {
                    $maxCharId++;
                    $characterId = $maxCharId;
                    $knownCharacters[$nameKey] = $characterId;
                    $newCharsCount++;
                }

                $image = $character['image']['original'] ?? $character['image']['medium'] ?? $credit['person']['image']['original'] ?? null;
                $gender = $credit['person']['gender'] ?? 'unknown';

                $pdo->prepare("
                    INSERT INTO characters
                        (id, name, species, status, gender, origin, location, image)
                    VALUES (:id, :name, 'unknown', 'unknown', :gender, 'unknown', 'unknown', :image)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        gender = IF(characters.gender IS NULL OR characters.gender = '' OR characters.gender = 'unknown', VALUES(gender), characters.gender),
                        image = COALESCE(characters.image, VALUES(image))
                ")->execute([
                    ':id' => $characterId,
                    ':name' => $charName,
                    ':gender' => $gender,
                    ':image' => $image,
                ]);

                $pdo->prepare("
                    INSERT IGNORE INTO character_episode (character_id, episode_id)
                    VALUES (:character_id, :episode_id)
                ")->execute([
                    ':character_id' => $characterId,
                    ':episode_id' => $epId,
                ]);
            }
        } catch (Exception $e) {
            echo "⚠️ Notice: could not fetch guest cast for episode {$episode['id']}: " . $e->getMessage() . "\n";
        }

        usleep(250000); // 250ms sleep between episode queries to respect TVMaze rate limits
    }

    echo "✅ Imported TVMaze seasons ($importedEpCount episodes, $newCharsCount new characters)\n";
}

// === 1. Import Characters ===
$page = 1;
do {
    $query = <<<GRAPHQL
    {
      characters(page: $page) {
        info { pages }
        results {
          id name species status gender image
          origin { name }
          location { name }
          episode { id name episode }
        }
      }
    }
    GRAPHQL;

    $data = graphql_query($query);
    $characters = $data['data']['characters']['results'] ?? [];
    $totalPages = $data['data']['characters']['info']['pages'] ?? 1;

    foreach ($characters as $char) {
        $stmt = $pdo->prepare("
            INSERT INTO characters (id, name, species, status, gender, origin, location, image)
            VALUES (:id, :name, :species, :status, :gender, :origin, :location, :image)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name), species = VALUES(species), status = VALUES(status),
                gender = VALUES(gender), origin = VALUES(origin), location = VALUES(location), image = VALUES(image)
        ");

        $stmt->execute([
            ':id' => $char['id'],
            ':name' => $char['name'],
            ':species' => $char['species'],
            ':status' => $char['status'],
            ':gender' => $char['gender'],
            ':origin' => $char['origin']['name'] ?? '',
            ':location' => $char['location']['name'] ?? '',
            ':image' => $char['image']
        ]);

        foreach ($char['episode'] as $ep) {
            $pdo->prepare("
                INSERT IGNORE INTO episodes (id, name, code)
                VALUES (:id, :name, :code)
            ")->execute([
                ':id' => $ep['id'],
                ':name' => $ep['name'],
                ':code' => $ep['episode']
            ]);

            $pdo->prepare("
                INSERT IGNORE INTO character_episode (character_id, episode_id)
                VALUES (:cid, :eid)
            ")->execute([
                ':cid' => $char['id'],
                ':eid' => $ep['id']
            ]);
        }
    }

    echo "✅ Imported character page $page\n";
    $page++;
} while ($page <= $totalPages);

// === 2. Import Episodes ===
$page = 1;
do {
    $query = <<<GRAPHQL
    {
      episodes(page: $page) {
        info { pages }
        results {
          id name episode air_date
        }
      }
    }
    GRAPHQL;

    $data = graphql_query($query);
    $episodes = $data['data']['episodes']['results'] ?? [];
    $totalPages = $data['data']['episodes']['info']['pages'] ?? 1;

    foreach ($episodes as $ep) {
        $pdo->prepare("
            INSERT INTO episodes (id, name, code, air_date)
            VALUES (:id, :name, :code, :air_date)
            ON DUPLICATE KEY UPDATE
              name = VALUES(name), code = VALUES(code), air_date = VALUES(air_date)
        ")->execute([
            ':id' => $ep['id'],
            ':name' => $ep['name'],
            ':code' => $ep['episode'],
            ':air_date' => $ep['air_date']
        ]);
    }

    echo "✅ Imported episode page $page\n";
    $page++;
} while ($page <= $totalPages);

// === 3. Import Locations ===
$page = 1;
do {
    $query = <<<GRAPHQL
    {
      locations(page: $page) {
        info { pages }
        results {
          id name type dimension
        }
      }
    }
    GRAPHQL;

    $data = graphql_query($query);
    $locations = $data['data']['locations']['results'] ?? [];
    $totalPages = $data['data']['locations']['info']['pages'] ?? 1;

    foreach ($locations as $loc) {
        $pdo->prepare("
            INSERT INTO locations (id, name, type, dimension)
            VALUES (:id, :name, :type, :dimension)
            ON DUPLICATE KEY UPDATE 
                name = VALUES(name), type = VALUES(type), dimension = VALUES(dimension)
        ")->execute([
            ':id' => $loc['id'],
            ':name' => $loc['name'],
            ':type' => $loc['type'],
            ':dimension' => $loc['dimension']
        ]);
    }

    echo "✅ Imported location page $page\n";
    $page++;
} while ($page <= $totalPages);

// === 4. Optional supplemental data (for seasons not present in the API) ===
import_supplemental_data($pdo, __DIR__ . '/supplemental_data.json');

// === 5. TVMaze source for missing seasons ===
import_tvmaze($pdo);

echo "🎉 All data imported successfully.\n";

