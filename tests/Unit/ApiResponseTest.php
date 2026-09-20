<?php

namespace Tests\Unit;

use App\Enums\ApiErrorCode;
use App\Support\ApiResponse;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    public function test_success_envelope_shape(): void
    {
        $response = ApiResponse::success(['id' => 1], 'Fetched.');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'code' => 'ok',
            'message' => 'Fetched.',
            'data' => ['id' => 1],
            'errors' => null,
        ], $response->getData(true));
    }

    public function test_error_envelope_shape(): void
    {
        $response = ApiResponse::error(ApiErrorCode::NotFound, 'Not found.', 404);

        $this->assertSame(404, $response->getStatusCode());
        $body = $response->getData(true);
        $this->assertFalse($body['success']);
        $this->assertSame('not_found', $body['code']);
        $this->assertSame('Not found.', $body['message']);
        $this->assertNull($body['data']);
    }

    public function test_error_envelope_carries_field_errors(): void
    {
        $response = ApiResponse::error(
            ApiErrorCode::ValidationFailed,
            'The given data was invalid.',
            422,
            ['email' => ['The email field is required.']],
        );

        $body = $response->getData(true);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('The email field is required.', $body['errors']['email'][0]);
    }
}
