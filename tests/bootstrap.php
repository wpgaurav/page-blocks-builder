<?php
/**
 * Unit bootstrap. Deliberately does not load WordPress: everything under
 * tests/unit exercises pure string transforms, and keeping WordPress out is
 * what makes the suite run in milliseconds on every push.
 */
require_once __DIR__ . '/../includes/class-gt-pb-text.php';
