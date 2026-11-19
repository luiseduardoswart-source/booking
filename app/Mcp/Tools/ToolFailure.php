<?php

namespace App\Mcp\Tools;

use RuntimeException;

/**
 * Something the person (through Claude) asked that cannot be done: an
 * unknown accommodation, a date in the wrong order. BookingTool turns it into
 * an error message Claude can read and explain.
 */
class ToolFailure extends RuntimeException {}
