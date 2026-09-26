<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Serves the API v1 OpenAPI 3.0 document (HMS_PLAN.md §22 — "OpenAPI docs
 * published"). Hand-maintained alongside the routes; the standard ApiResponse
 * envelope is described once as a reusable schema. Public — it's just docs.
 */
class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json($this->spec());
    }

    /** @return array<string,mixed> */
    private function spec(): array
    {
        $envelope = [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'code' => ['type' => 'string'],
                'message' => ['type' => 'string', 'nullable' => true],
                'data' => ['nullable' => true],
                'errors' => ['type' => 'object', 'nullable' => true],
            ],
        ];

        $secured = [['bearerAuth' => []]];
        $ok = ['description' => 'Success envelope', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Envelope']]]];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'True-Doctor HMS API',
                'version' => '1.0.0',
                'description' => 'Sanctum-authenticated JSON API. Every response uses the standard envelope '
                    .'{success, code, message, data, errors}; lists add a `meta` block. RBAC mirrors the '
                    .'admin panel (Policies); all data is scoped to the token user’s hospital.',
            ],
            'servers' => [['url' => url('/api/v1'), 'description' => 'v1']],
            'components' => [
                'securitySchemes' => ['bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum personal access token']],
                'schemas' => ['Envelope' => $envelope],
            ],
            'paths' => [
                '/auth/login' => ['post' => [
                    'summary' => 'Sign in and receive a token', 'tags' => ['Auth'],
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['email', 'password'], 'properties' => ['email' => ['type' => 'string'], 'password' => ['type' => 'string'], 'device_name' => ['type' => 'string']]]]]],
                    'responses' => ['200' => $ok, '401' => $ok, '403' => $ok],
                ]],
                '/auth/me' => ['get' => ['summary' => 'Current user', 'tags' => ['Auth'], 'security' => $secured, 'responses' => ['200' => $ok, '401' => $ok]]],
                '/auth/logout' => ['post' => ['summary' => 'Revoke the current token', 'tags' => ['Auth'], 'security' => $secured, 'responses' => ['200' => $ok]]],
                '/auth/password' => ['post' => [
                    'summary' => 'Change your own password (clears a temporary one; signs out other devices)', 'tags' => ['Auth'], 'security' => $secured,
                    'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['current_password', 'password', 'password_confirmation'], 'properties' => ['current_password' => ['type' => 'string'], 'password' => ['type' => 'string', 'minLength' => 6], 'password_confirmation' => ['type' => 'string']]]]]],
                    'responses' => ['200' => $ok, '422' => $ok],
                ]],
                '/meta' => ['get' => ['summary' => 'Hospital, money format, subscription, menu and status lists for drawing the app', 'tags' => ['App'], 'security' => $secured, 'responses' => ['200' => $ok, '401' => $ok]]],

                '/patients' => [
                    'get' => ['summary' => 'List patients', 'tags' => ['Patients'], 'security' => $secured, 'parameters' => [$this->q('q'), $this->q('status'), $this->q('per_page')], 'responses' => ['200' => $ok, '403' => $ok]],
                    'post' => ['summary' => 'Register a patient', 'tags' => ['Patients'], 'security' => $secured, 'responses' => ['201' => $ok, '403' => $ok, '422' => $ok]],
                ],
                '/patients/{uuid}' => [
                    'get' => ['summary' => 'Get a patient', 'tags' => ['Patients'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '404' => $ok]],
                    'put' => ['summary' => 'Update a patient', 'tags' => ['Patients'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '403' => $ok, '404' => $ok]],
                    'delete' => ['summary' => 'Archive a patient', 'tags' => ['Patients'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '403' => $ok]],
                ],

                '/patients/{uuid}/brief' => ['get' => ['summary' => 'Before opening a visit: open visit, booking today, money owed', 'tags' => ['Patients'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '404' => $ok]]],

                '/appointments' => [
                    'get' => ['summary' => 'List appointments', 'tags' => ['Appointments'], 'security' => $secured, 'parameters' => [$this->q('date'), $this->q('status'), $this->q('doctor')], 'responses' => ['200' => $ok]],
                    'post' => ['summary' => 'Book an appointment', 'tags' => ['Appointments'], 'security' => $secured, 'responses' => ['201' => $ok, '422' => $ok]],
                ],
                '/appointments/{uuid}/transition' => ['post' => ['summary' => 'Advance appointment status', 'tags' => ['Appointments'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '422' => $ok]]],
                '/appointments/{uuid}/outcome' => ['post' => ['summary' => 'Record what was done and complete the appointment', 'tags' => ['Appointments'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '422' => $ok]]],
                '/queue' => ['get' => ['summary' => "Today's check-in queue: lanes, clocks and tally", 'tags' => ['Appointments'], 'security' => $secured, 'responses' => ['200' => $ok, '403' => $ok]]],
                '/dashboard' => ['get' => ['summary' => 'The dashboard stat cards for this user', 'tags' => ['App'], 'security' => $secured, 'responses' => ['200' => $ok]]],

                '/visits' => [
                    'get' => ['summary' => 'List visits', 'tags' => ['Visits'], 'security' => $secured, 'parameters' => [$this->q('q'), $this->q('status'), $this->q('stage'), $this->q('patient'), $this->q('open'), $this->q('per_page')], 'responses' => ['200' => $ok]],
                    'post' => ['summary' => 'Open a visit (with desk vitals and start_now)', 'tags' => ['Visits'], 'security' => $secured, 'responses' => ['201' => $ok, '403' => $ok, '422' => $ok]],
                ],
                '/visits/phrases' => ['get' => ['summary' => 'Suggested reasons, complaints and diagnoses for the open-visit form', 'tags' => ['Visits'], 'security' => $secured, 'responses' => ['200' => $ok, '403' => $ok]]],
                '/visits/{uuid}/writing-aids' => ['get' => ['summary' => 'Allergies, vitals, last diagnosis and suggested words for writing notes', 'tags' => ['Visits'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '403' => $ok]]],
                '/visits/intake' => ['post' => ['summary' => 'Register a new patient and open their visit', 'tags' => ['Visits'], 'security' => $secured, 'responses' => ['201' => $ok, '403' => $ok, '422' => $ok]]],
                '/visits/{uuid}' => ['get' => ['summary' => 'Get a visit, with its gate and history', 'tags' => ['Visits'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '404' => $ok]]],
                '/visits/{uuid}/cancel' => ['post' => ['summary' => 'Cancel a visit (reason required)', 'tags' => ['Visits'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '422' => $ok]]],
                '/visits/{uuid}/vitals' => ['post' => ['summary' => 'Record vitals (BMI computed)', 'tags' => ['Visits'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '403' => $ok]]],
                '/visits/{uuid}/clinical' => ['post' => ['summary' => 'Save clinical notes / diagnosis', 'tags' => ['Visits'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '403' => $ok]]],
                '/visits/{uuid}/transition' => ['post' => ['summary' => 'Move the visit to its next stage, if its gate is open', 'tags' => ['Visits'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '422' => $ok]]],

                '/stock-items' => ['get' => ['summary' => 'List stock items', 'tags' => ['Inventory'], 'security' => $secured, 'parameters' => [$this->q('filter'), $this->q('category'), $this->q('q')], 'responses' => ['200' => $ok, '403' => $ok]]],
                '/lab-orders' => ['get' => ['summary' => 'The lab worklist, with the bench tally in meta', 'tags' => ['Lab'], 'security' => $secured, 'parameters' => [$this->q('q'), $this->q('status'), $this->q('outstanding'), $this->q('per_page')], 'responses' => ['200' => $ok, '403' => $ok]]],
                '/lab-orders/{uuid}/transition' => ['post' => ['summary' => 'Move a lab order (collected, processing, completed, cancelled)', 'tags' => ['Lab'], 'security' => $secured, 'parameters' => [$this->path('uuid')], 'responses' => ['200' => $ok, '403' => $ok, '422' => $ok]]],
                '/invoices' => ['get' => ['summary' => 'List invoices', 'tags' => ['Billing'], 'security' => $secured, 'parameters' => [$this->q('q'), $this->q('status')], 'responses' => ['200' => $ok, '403' => $ok]]],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function q(string $name): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
    }

    /** @return array<string,mixed> */
    private function path(string $name): array
    {
        return ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
    }
}
