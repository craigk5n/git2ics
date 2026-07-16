<?php

declare(strict_types=1);

namespace K5n\Git2Ics\Exception;

/**
 * The output stream could not be written to.
 *
 * Distinct from a generic failure because the two causes deserve opposite
 * treatment: a closed stdout means a downstream reader exited (`git2ics | head`)
 * and is entirely normal, whereas a failed write to a named file is a real
 * error. Only the caller knows which stream it handed over, so it decides.
 */
final class OutputWriteException extends Git2IcsException
{
}
