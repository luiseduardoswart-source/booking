<?php

namespace App\Support;

use RuntimeException;

/**
 * The uploaded document yields no text. The message is already translated and
 * meant for the user; it contains no technical details.
 */
class DocumentNotReadable extends RuntimeException {}
