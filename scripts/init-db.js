const path = require("path");
const { init } = require("../database");

init();

setTimeout(() => {
  const dbPath = path.join(__dirname, "..", "club.db");
  console.log(`Database schema initialized at ${dbPath}`);
  process.exit(0);
}, 500);
