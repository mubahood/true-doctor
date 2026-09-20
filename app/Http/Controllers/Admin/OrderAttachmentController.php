<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabOrder;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\RadiologyOrder;
use App\Services\OrderAttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one order attachment. Results and films never sit on a public disk;
 * the download comes through here so the visit policy (and the tenant scope)
 * gate every read. Uploading and removing them is the order dialog.
 */
class OrderAttachmentController extends Controller
{
    public function __construct(private readonly OrderAttachmentService $service) {}

    public function download(Order $order, OrderAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $order->visit);
        abort_unless($attachment->belongsToWork($order), 404);
        abort_unless($this->service->disk()->exists($attachment->file_path), 404);

        return $this->service->disk()->download($attachment->file_path, $attachment->original_name);
    }

    /**
     * The same file, owned by a lab order.
     *
     * Gated on `lab.view` rather than the visit policy: a result belongs to
     * the bench that produced it, and a lab order is not always raised from a
     * visit somebody can see.
     */
    public function lab(LabOrder $labOrder, OrderAttachment $attachment): StreamedResponse
    {
        abort_unless(auth()->user()?->can('lab.view') === true, 403);

        return $this->stream($labOrder, $attachment);
    }

    public function radiology(RadiologyOrder $radiologyOrder, OrderAttachment $attachment): StreamedResponse
    {
        abort_unless(auth()->user()?->can('radiology.view') === true, 403);

        return $this->stream($radiologyOrder, $attachment);
    }

    private function stream(\App\Models\Contracts\HoldsAttachments $owner, OrderAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->belongsToWork($owner), 404);
        abort_unless($this->service->disk()->exists($attachment->file_path), 404);

        return $this->service->disk()->download($attachment->file_path, $attachment->original_name);
    }
}
