<?php
use PHPUnit\Framework\TestCase;

final class LicensePackageTrustTest extends TestCase {
	private function package( $url ): string {
		$instance = ( new ReflectionClass( GT_PB_License_Manager::class ) )->newInstanceWithoutConstructor();
		return ( new ReflectionMethod( GT_PB_License_Manager::class, 'trusted_package_url' ) )->invoke( $instance, $url );
	}

	public function test_license_server_and_exact_r2_package_host_are_allowed(): void {
		$legacy = 'https://gauravtiwari.org/?fluent-cart=download_license_package';
		$r2 = 'https://' . GT_PB_License_Manager::PACKAGE_HOST . '/page-blocks-builder-v3.0.1.zip?X-Amz-Signature=fixture';
		$this->assertSame( $legacy, $this->package( $legacy ) );
		$this->assertSame( $r2, $this->package( $r2 ) );
	}

	public function test_lookalikes_other_buckets_other_products_and_insecure_urls_are_rejected(): void {
		$host = GT_PB_License_Manager::PACKAGE_HOST;
		foreach ( array(
			'http://' . $host . '/page-blocks-builder-v3.0.1.zip',
			'https://' . $host . '.example.test/page-blocks-builder-v3.0.1.zip',
			'https://another-bucket.example.r2.cloudflarestorage.com/page-blocks-builder-v3.0.1.zip',
			'https://' . $host . '/another-plugin-v3.0.1.zip',
			'https://' . $host . '/page-blocks-builder-v3.0.1.zip/redirect',
			'https://user:password@' . $host . '/page-blocks-builder-v3.0.1.zip',
			'https://' . $host . ':8080/page-blocks-builder-v3.0.1.zip',
		) as $url ) $this->assertSame( '', $this->package( $url ) );
	}
}
