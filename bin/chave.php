<?php

declare(strict_types=1);

/**
 * Gera uma APP_KEY para colar no .env.
 *
 *   php bin/chave.php
 */

echo base64_encode(random_bytes(32)) . PHP_EOL;
