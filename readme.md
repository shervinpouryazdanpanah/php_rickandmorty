# Rick and Morty GraphQL Importer (PHP + PDO)

This project fetches data from the [Rick and Morty GraphQL API](https://rickandmortyapi.com/graphql) and imports it into a MySQL database using **PDO** and **cURL**.

## 📦 Features

- Imports:
  - Characters (with origin, location, image, gender, etc.)
  - Episodes (with air dates)
  - Locations (with type and dimension)
- Automatically creates required MySQL tables.
- Handles pagination.
- Uses `ON DUPLICATE KEY UPDATE` and `INSERT IGNORE` to prevent duplicate data.

---

## 🛠 Requirements

- PHP 7.4+ with PDO and cURL extensions
- MySQL or MariaDB
- Docker (optional, for quick setup)

---

## ⚙️ Configuration

Edit the database connection values at the top of `import.php`:

```php
$host = "hostname";
$user = "username";
$password = "password";
$database = "database";
```

## 🚀 Usage

Run the project:

```bash
docker-compose up --build
```

---

## 📂 Tables Created

- `characters`
- `episodes`
- `character_episode`
- `locations`

All tables use `InnoDB` with UTF-8 support (`utf8mb4`).

---

## ✅ Sample Output

```
pgsqlCopyEdit✅ Imported character page 1
✅ Imported episode page 1
✅ Imported location page 1
🎉 All data imported successfully.
```

---

## 🧼 Cleanup

To stop and remove containers if using Docker:

```bash
docker-compose down
```

---

## 🧠 Notes

- The script gracefully handles pagination.
- No duplicate entries are inserted.
- Foreign key constraints are used to maintain relational integrity.

---

## 📝 License

MIT License – Use freely, modify and contribute.
