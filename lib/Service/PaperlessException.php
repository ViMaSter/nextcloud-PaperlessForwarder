<?php

declare(strict_types=1);

namespace OCA\PaperlessForwarder\Service;

use RuntimeException;

class PaperlessException extends RuntimeException {
}

final class UploadRejected extends PaperlessException {
}
