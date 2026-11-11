<?php

declare(strict_types=1);

namespace OCA\PhoneSystem\Tests;

use PHPUnit\Framework\TestCase;

/** Nextcloud 34 removed \OC::$server->getURLGenerator() and broke the admin page; only the public API (OCP) is safe across versions. */
final class AdminSettingsTest extends TestCase {
	public function testNoTemplateOrClassReachesIntoNextcloudsPrivateApi(): void {
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../phone_system', \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression('/\\\\OC::|\\\\OC_[A-Z]|\\bOC\\\\(?!P)/', file_get_contents($file->getPathname()), $file->getPathname());
		}
	}
}
