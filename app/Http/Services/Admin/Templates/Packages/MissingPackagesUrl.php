<?php

namespace App\Http\Services\Admin\Templates\Packages;

/** `TEMPLATE_PACKAGES_URL` is missing outside local: package files must not be addressed on the API's own origin. */
final class MissingPackagesUrl extends \RuntimeException {}
