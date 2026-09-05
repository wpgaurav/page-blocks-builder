<?php
/**
 * tags and description shipped as columns in schema 1.2 and were writable
 * nowhere: extract_data() accepted them over REST and sanitize_data() dropped
 * them, so a PUT returned 200 and lost the data silently.
 */

use PHPUnit\Framework\TestCase;

final class ColumnRoundTripTest extends TestCase {

	private array $created = array();

	protected function tearDown(): void {
		$db = new gt_pb_db();
		foreach ( $this->created as $id ) {
			$db->delete( $id );
		}
		$this->created = array();
	}

	private function make( array $data ): int {
		$id = (int) ( new gt_pb_db() )->insert( $data + array( 'status' => 'publish', 'content' => 'x' ) );
		$this->created[] = $id;
		return $id;
	}

	public function test_tags_and_description_survive_a_direct_write(): void {
		$id  = $this->make( array( 'title' => 'Cols direct', 'tags' => 'hero, cta', 'description' => 'A hero band.' ) );
		$row = ( new gt_pb_db() )->get( $id );

		$this->assertSame( 'hero, cta', $row->tags );
		$this->assertSame( 'A hero band.', $row->description );
	}

	public function test_tags_are_normalised(): void {
		$id  = $this->make( array( 'title' => 'Cols norm', 'tags' => '  hero ,, ,  cta  ' ) );
		$row = ( new gt_pb_db() )->get( $id );

		$this->assertSame( 'hero, cta', $row->tags, 'blank entries and stray spacing should collapse' );
	}

	public function test_tags_accept_an_array(): void {
		$id  = $this->make( array( 'title' => 'Cols array', 'tags' => array( 'hero', 'cta' ) ) );
		$this->assertSame( 'hero, cta', ( new gt_pb_db() )->get( $id )->tags );
	}

	public function test_they_survive_an_update_not_just_an_insert(): void {
		$id = $this->make( array( 'title' => 'Cols update' ) );
		( new gt_pb_db() )->update( $id, array( 'tags' => 'later', 'description' => 'Set on update.' ) );
		$row = ( new gt_pb_db() )->get( $id );

		$this->assertSame( 'later', $row->tags );
		$this->assertSame( 'Set on update.', $row->description );
	}

	/**
	 * The path that actually lied: REST accepted these and threw them away.
	 */
	public function test_they_round_trip_through_the_rest_controller(): void {
		$id = $this->make( array( 'title' => 'Cols rest' ) );

		$request = new WP_REST_Request( 'PUT', '/pbb/v1/blocks/' . $id );
		// The route string alone does not populate $request['id']; without this
		// update_item() resolves id 0 and returns a 404 the test would ignore.
		$request->set_url_params( array( 'id' => $id ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array(
			'tags'        => 'rest, roundtrip',
			'description' => 'Written over REST.',
		) ) );

		$rest     = new gt_pb_rest_api( new gt_pb_db(), $GLOBALS['gt_page_blocks_builder'] );
		$response = $rest->update_item( $request );
		$this->assertNotInstanceOf( WP_Error::class, $response, 'the REST update itself failed' );

		$row = ( new gt_pb_db() )->get( $id );
		$this->assertSame( 'rest, roundtrip', $row->tags,
			'a REST write carrying tags returned success and dropped them' );
		$this->assertSame( 'Written over REST.', $row->description );

		$read = $rest->prepare_item( $row );
		$this->assertSame( 'rest, roundtrip', $read['tags'] );
		$this->assertSame( 'Written over REST.', $read['description'] );
	}

	public function test_description_is_sanitized(): void {
		$id  = $this->make( array( 'title' => 'Cols xss', 'description' => '<script>alert(1)</script>Fine text' ) );
		$row = ( new gt_pb_db() )->get( $id );

		$this->assertStringNotContainsString( '<script', $row->description );
		$this->assertStringContainsString( 'Fine text', $row->description );
	}
}
