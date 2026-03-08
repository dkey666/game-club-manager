const Database = require("better-sqlite3");
const path = require("path");

function init() {
  const dbPath = path.join(__dirname, process.env.DB_NAME || "club.db");
  const db = new Database(dbPath);

  db.pragma("journal_mode = WAL");
  db.pragma("foreign_keys = ON");

  db.exec(`
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY,
      telegram_id INTEGER UNIQUE,
      username TEXT,
      first_name TEXT,
      name TEXT,
      phone TEXT,
      points INTEGER DEFAULT 0,
      points_registered INTEGER DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS computers (
      id INTEGER PRIMARY KEY,
      hall INTEGER,
      status TEXT DEFAULT 'free'
    );

    CREATE TABLE IF NOT EXISTS tasks (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT,
      description TEXT,
      link TEXT,
      points INTEGER,
      active INTEGER DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS user_tasks (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      task_id INTEGER,
      completed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      UNIQUE(user_id, task_id)
    );

    CREATE TABLE IF NOT EXISTS bookings (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      computer_id INTEGER,
      user_name TEXT,
      user_phone TEXT,
      status TEXT DEFAULT 'pending',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS points_history (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      amount INTEGER,
      action TEXT,
      reason TEXT,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
  `);

  const computerCount = db.prepare("SELECT COUNT(*) AS count FROM computers").get().count;
  if (computerCount === 0) {
    const insertComputer = db.prepare("INSERT INTO computers (id, hall) VALUES (?, ?)");
    const insertMany = db.transaction(() => {
      for (let i = 1; i <= 30; i += 1) {
        insertComputer.run(i, Math.ceil(i / 10));
      }
    });
    insertMany();
  }

  const taskCount = db.prepare("SELECT COUNT(*) AS count FROM tasks").get().count;
  if (taskCount === 0) {
    db.prepare(`
      INSERT INTO tasks (title, description, link, points)
      VALUES (?, ?, ?, ?)
    `).run(
      "Follow on Instagram",
      "Subscribe to the club Instagram account and receive starter reward points.",
      "https://instagram.com/dkxgameclub",
      1000
    );
  }

  db.close();
  return dbPath;
}

module.exports = { init };
