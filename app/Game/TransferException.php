<?php

namespace App\Game;

use RuntimeException;

/** A transfer action that isn't allowed. The message is shown to the manager as it is. */
final class TransferException extends RuntimeException
{
}
