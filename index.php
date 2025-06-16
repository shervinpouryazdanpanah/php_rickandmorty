<?php
$host = "mysql";
$user = "root";
$password = "root";
$database = "countries";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
    die("DB ERROR: " . $e->getMessage());
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

echo "🎉 All data imported successfully.\n";
