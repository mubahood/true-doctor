<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Services\HospitalProfileService;
use App\Support\ApiResponse;
use App\Support\HospitalSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Hospital letterhead (manage-settings): the hospital's name,
 * address, contact lines, footer, brand colour, tagline and its two logos —
 * what is printed on every document. The logo and wide logo are uploaded as
 * multipart files (`logo`, `wordmark`; PNG/JPEG/GIF up to 2 MB); the update is
 * a POST so a multipart body reaches PHP.
 */
class HospitalSettingsController extends Controller
{
    public function __construct(private readonly HospitalProfileService $profiles) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->profiles->current($this->hospital($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $hospital = $this->hospital($request);
        $current = $this->profiles->current($hospital);

        // A save is the whole form, as on the web; what was not sent keeps its
        // stored value, so a client that only changes the tagline cannot blank the rest.
        $input = $request->only(['name', 'address', 'phone', 'email', 'website', 'registration', 'footer', 'tagline', 'brand_colour']) + [
            'name' => $current['name'], 'address' => $current['address'], 'phone' => $current['phone'],
            'email' => $current['email'], 'website' => $current['website'], 'registration' => $current['registration'],
            'footer' => $current['footer'], 'tagline' => $current['tagline'], 'brand_colour' => $current['brand_colour'],
        ];
        $input['logo'] = $request->file('logo');
        $input['wordmark'] = $request->file('wordmark');

        $data = validator($input, $this->profiles->rules(), [], ['brand_colour' => 'brand colour', 'wordmark' => 'wide logo'])->validate();

        $this->profiles->save($hospital, $data, $request->file('logo'), $request->file('wordmark'));

        return ApiResponse::success($this->profiles->current($hospital->fresh()), 'Letterhead and brand saved.');
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $hospital = $this->profiles->removeLogo($this->hospital($request));

        return ApiResponse::success($this->profiles->current($hospital->fresh()), 'Logo removed.');
    }

    public function removeWordmark(Request $request): JsonResponse
    {
        $hospital = $this->profiles->removeWordmark($this->hospital($request));

        return ApiResponse::success($this->profiles->current($hospital->fresh()), 'Wide logo removed.');
    }

    private function hospital(Request $request): Hospital
    {
        abort_unless($request->user()->can('manage-settings'), 403);

        $hospital = app(HospitalSettings::class)->hospital();
        abort_if($hospital === null, 404, 'No hospital in context.');

        return $hospital;
    }
}
