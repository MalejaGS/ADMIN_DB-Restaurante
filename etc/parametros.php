<?php
// También se admiten PGPASSWORD, PGSERVICE y ~/.pgpass (libpq).
$host = getenv('PGHOST') ?: 'localhost';
$dbname = getenv('PGDATABASE') ?: 'restaurante';
$user = getenv('PGUSER') ?: 'admin_db';
$port = getenv('PGPORT') ?: '5432';
