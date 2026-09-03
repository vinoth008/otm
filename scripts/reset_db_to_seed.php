<?php
declare(strict_types=1);
/**
 * Database Reset and Seed Script
 * Cleans the database back to a canonical seed state and re-creates
 * the 4 demo users (admin1/staff1/recept1/customer1@gmail.com).
 *
 * This is now a thin wrapper around the authoritative setup+seed script.
 * Run: php scripts/reset_db_to_seed.php   (or)   php database/seed_database.php
 */
require_once __DIR__ . '/../database/seed_database.php';
