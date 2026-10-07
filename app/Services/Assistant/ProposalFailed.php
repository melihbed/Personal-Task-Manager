<?php

namespace App\Services\Assistant;

use RuntimeException;

/** A proposed change cannot be made as asked; the message says why. */
class ProposalFailed extends RuntimeException {}
