<?php

namespace App\Mcp\Tools;

use RuntimeException;

/**
 * Something the person (through the AI app) asked that cannot be done: an
 * unknown accommodation, a date in the wrong order. BookingTool turns it into
 * an error message the AI app can read and explain.
 */
class ToolFailure extends RuntimeException {}
