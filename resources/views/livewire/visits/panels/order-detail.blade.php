<div>
  @php($order = $this->order)

  <x-ui.modal size="xl" show="show" autosaves title="{{ $order?->title ?? 'Order' }}">
    @if($order)
      <div class="tb-modal-sub">
        <i class="fas {{ $order->type->icon() }}" aria-hidden="true"></i>
        <span>{{ $order->type->label() }}</span>
        <x-ui.badge :tone="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge>
        <span class="sep">·</span>
        <span class="muted">{{ $order->assignee?->name ?? 'Unassigned' }}</span>
        @if($order->department)
          <span class="sep">·</span><span class="muted">{{ $order->department->name }}</span>
        @endif
        <span class="sep">·</span>
        <span class="muted">{{ $order->created_at->format('d M · H:i') }}</span>
        @if($order->requester)
          <span class="muted">by {{ $order->requester->name }}</span>
        @endif
      </div>

      @if($confirming)
        {{-- ── What cancelling costs, before it costs it ──────────────
             Not a wire:confirm. This reverses money and puts goods back on a
             shelf; whoever presses it should see exactly how much of each. --}}
        <div class="tb-modal-body">
          <div class="tb-undo">
            <div class="tb-undo-mark"><i class="fas fa-rotate-left" aria-hidden="true"></i></div>
            <h3>Cancel this order?</h3>
            <p class="muted">Here is everything that gets undone.</p>
          </div>

          <div class="tb-undo-cols">
            <section>
              <h4 class="tb-label">Off the bill</h4>
              @if($this->plan['lines'])
                <ul class="tb-undo-list">
                  @foreach($this->plan['lines'] as $line)
                    <li wire:key="undo-line-{{ $loop->index }}">
                      <span>{{ $line['name'] }}</span>
                      @if($line['quantity'] !== '1')<span class="q">× {{ $line['quantity'] }}</span>@endif
                      <b>−<x-ui.money :amount="$line['amount']" /></b>
                    </li>
                  @endforeach
                </ul>
                <p class="tb-undo-total">
                  <span>Comes off the bill</span>
                  <b>−<x-ui.money :amount="$this->plan['total']" /></b>
                </p>
              @else
                <x-ui.empty compact icon="fa-file-invoice-dollar" message="Nothing was charged to it." />
              @endif
            </section>

            <section>
              <h4 class="tb-label">Back on the shelf</h4>
              @if($this->plan['stock'])
                <ul class="tb-undo-list">
                  @foreach($this->plan['stock'] as $back)
                    <li wire:key="undo-stock-{{ $loop->index }}">
                      <span>{{ $back['name'] }}</span>
                      <b>+{{ $back['quantity'] }} {{ $back['unit'] }}</b>
                    </li>
                  @endforeach
                </ul>
              @else
                <x-ui.empty compact icon="fa-boxes-stacked" message="Nothing came off the shelf." />
              @endif
            </section>
          </div>

          <x-ui.field label="Why is it being cancelled?" for="ord-why" name="cancelReason">
            <textarea id="ord-why" class="tb-textarea" rows="2" maxlength="500"
                      placeholder="Ordered in error · patient declined · duplicate"
                      wire:model="cancelReason"></textarea>
          </x-ui.field>
        </div>

        <div class="tb-modal-foot">
          <button type="button" class="btn-tb btn-tb-ghost" wire:click="keepOrder">Keep the order</button>
          <button type="button" class="btn-tb btn-tb-danger" wire:click="cancelOrder"
                  wire:loading.attr="disabled" wire:target="cancelOrder">
            <span wire:loading.remove wire:target="cancelOrder">
              <i class="fas fa-ban" aria-hidden="true"></i> Cancel and reverse
            </span>
            <span wire:loading wire:target="cancelOrder">Reversing…</span>
          </button>
        </div>

      @else
        <div class="tb-modal-body tb-ordm">

          {{-- ── The facts, compact: this is the smallest part of the page --}}
          {{-- Only what actually happened. Who raised it and when are in the
               strip above; repeating them here left a half-empty grid where
               the work should be. --}}
          @if($order->started_at || $order->completed_at || $order->cancelled_at)
            <div class="tb-ord-trail">
              @if($order->started_at)
                <span><i class="fas fa-play" aria-hidden="true"></i> Started {{ $order->started_at->format('d M · H:i') }}</span>
              @endif
              @if($order->completed_at)
                <span><i class="fas fa-check" aria-hidden="true"></i> Completed {{ $order->completed_at->format('d M · H:i') }}</span>
              @endif
              @if($order->cancelled_at)
                <span class="is-off"><i class="fas fa-ban" aria-hidden="true"></i> Cancelled {{ $order->cancelled_at->format('d M · H:i') }}</span>
              @endif
            </div>
          @endif

          @if($order->notes)
            <div class="tb-vs-notes"><p><span>Instructions</span>{{ $order->notes }}</p></div>
          @endif
          @if($order->cancel_reason)
            <div class="tb-vs-notes"><p><span>Cancelled because</span>{{ $order->cancel_reason }}</p></div>
          @endif

          {{-- ── The stay, when this order is one ───────────────────────
               Everything about where the patient is comes from the admission
               itself, so the inpatient module and this dialog cannot drift. --}}
          @if($this->stay)
            @php($stay = $this->stay)
            <div class="tb-stay">
              <div class="tb-stay-bed">
                <i class="fas fa-bed" aria-hidden="true"></i>
                <b>{{ $stay->bed?->ward?->name ?? 'Ward' }} · {{ $stay->bed?->name ?? 'No bed' }}</b>
                <x-ui.badge :tone="$stay->status->badge()">{{ $stay->status->label() }}</x-ui.badge>
              </div>
              <dl class="tb-vs-facts">
                <div><dt>Admitted</dt><dd>{{ $stay->admitted_at?->format('d M Y · H:i') ?? '—' }}</dd></div>
                <div><dt>Nights so far</dt><dd>{{ $stay->nights() }}</dd></div>
                <div><dt>Nightly rate</dt><dd><x-ui.money :amount="$stay->bed?->daily_charge ?? '0'" /></dd></div>
                <div><dt>Admitting doctor</dt><dd>{{ $stay->admittingDoctor?->name ?? '—' }}</dd></div>
                @if($stay->discharged_at)
                  <div><dt>Discharged</dt><dd>{{ $stay->discharged_at->format('d M Y · H:i') }}</dd></div>
                  <div><dt>Bed charge</dt><dd><x-ui.money :amount="$stay->bed_charge_total ?? '0'" /></dd></div>
                @endif
              </dl>
              @if($stay->status->isActive() && $this->canManage)
                <p class="tb-stay-note">
                  <i class="fas fa-circle-info" aria-hidden="true"></i>
                  Marking this completed discharges the patient: the bed is freed and the
                  nights are billed to this order.
                </p>
                <x-ui.field label="Discharge notes" for="ord-dnotes" name="notes">
                  <textarea id="ord-dnotes" class="tb-textarea" rows="2" maxlength="2000"
                            placeholder="How the stay ended" wire:model="notes"></textarea>
                </x-ui.field>
              @endif
              <x-ui.link :href="route('admin.admissions.show', $stay)" class="btn-tb btn-tb-sm btn-tb-ghost">
                Open the admission <i class="fas fa-arrow-right" aria-hidden="true"></i>
              </x-ui.link>
            </div>
          @endif

          @if(! $this->editable)
            <div class="tb-alert tb-mt-4" role="status">
              <i class="fas fa-lock" aria-hidden="true"></i>
              <span>{{ $order->status === \App\Enums\OrderStatus::Cancelled
                  ? 'This order was cancelled. Its record is kept exactly as it was.'
                  : 'This visit has been invoiced, so its charges can no longer change.' }}</span>
            </div>
          @endif

          {{-- ── What it used ──────────────────────────────────────────
               The items ARE the bill, and the material record of what the
               patient received. Adding one asks four things and nothing more. --}}
          <section class="tb-ordm-sec">
            <div class="tb-ordm-head">
              <h4 class="tb-label"><i class="fas fa-list-check" aria-hidden="true"></i> What it used</h4>
              @php($live = $order->items->filter(fn ($i) => $i->status->isBillable()))
              @if($live->isNotEmpty())
                <span class="tb-ordm-sum">
                  <x-ui.money :amount="$live->reduce(fn ($carry, $i) => bcadd($carry, (string) $i->line_total, 2), '0.00')" />
                </span>
              @endif
            </div>

            @if($order->items->isEmpty())
              <x-ui.empty compact icon="fa-file-invoice-dollar"
                          message="Nothing charged to this order yet." />
            @else
              <div class="tb-table-wrap">
                <table class="tb-table tb-ordm-table">
                  <caption class="sr-only">What this order used</caption>
                  <thead>
                    <tr>
                      <th>Item</th><th class="tb-text-right">Unit</th>
                      <th class="tb-text-right">Qty</th><th class="tb-text-right">Total</th>
                      <th><span class="sr-only">Remove</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    @foreach($order->items as $item)
                      @php($gone = $item->status === \App\Enums\OrderItemStatus::Cancelled)
                      @if($this->editingId === $item->id)
                        {{-- Corrected in place. On a product the quantity is
                             not just a number — it is how much left the shelf,
                             so saving moves stock either way. --}}
                        <tr wire:key="oi-edit-{{ $item->id }}" class="is-editing">
                          <td colspan="5">
                            <form wire:submit="saveItem" class="tb-line-edit">
                              <span class="tb-line-edit-name">
                                <i class="fas {{ $item->isProduct() ? 'fa-pills' : 'fa-hand-holding-medical' }}" aria-hidden="true"></i>
                                {{ $item->name }}
                              </span>
                              <x-ui.field label="Qty" for="ed-qty-{{ $item->id }}" name="editQty">
                                <input id="ed-qty-{{ $item->id }}" type="number" step="any" min="0.01"
                                       class="tb-input" wire:model="editQty">
                              </x-ui.field>
                              <x-ui.field label="Note" for="ed-note-{{ $item->id }}" name="editNote">
                                <input id="ed-note-{{ $item->id }}" type="text" maxlength="255"
                                       class="tb-input" placeholder="Why this is on the order"
                                       wire:model="editNote">
                              </x-ui.field>
                              <div class="tb-line-edit-acts">
                                <button type="button" class="btn-tb btn-tb-sm btn-tb-ghost" wire:click="cancelEdit">Cancel</button>
                                <button type="submit" class="btn-tb btn-tb-sm btn-tb-primary"
                                        wire:loading.attr="disabled" wire:target="saveItem">
                                  <span wire:loading.remove wire:target="saveItem">Save</span>
                                  <span wire:loading wire:target="saveItem">Saving…</span>
                                </button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      @else
                        <tr wire:key="oi-{{ $item->id }}" @class(['is-gone' => $gone])>
                          <td>
                            <span class="tb-ordm-kind" title="{{ $item->isProduct() ? 'From the pharmacy' : 'From the price list' }}">
                              <i class="fas {{ $item->isProduct() ? 'fa-pills' : 'fa-hand-holding-medical' }}" aria-hidden="true"></i>
                            </span>
                            {{ $item->name }}
                            @if($gone)<span class="tb-ordm-gone">removed</span>@endif

                            {{-- The words beside the charge, and the hands on
                                 it. A line that can be corrected has to say
                                 who corrected it. --}}
                            @if($item->notes)
                              <span class="tb-line-note">{{ $item->notes }}</span>
                            @endif
                            <span class="tb-line-who">
                              {{ $item->orderedBy?->name ?? 'Someone' }}
                              · {{ $item->created_at?->format('d M · H:i') }}
                              @if($item->wasCorrected())
                                <em>· changed by {{ $item->updatedBy?->name ?? 'someone' }}
                                  {{ $item->updated_at?->format('d M · H:i') }}</em>
                              @endif
                            </span>
                          </td>
                          <td class="tb-text-right muted"><x-ui.money :amount="$item->unit_price" /></td>
                          <td class="tb-text-right">{{ $item->tidyQuantity() }}</td>
                          <td class="tb-text-right"><b><x-ui.money :amount="$item->line_total" /></b></td>
                          <td class="tb-text-right tb-nowrap">
                            @if($this->editable && ! $gone)
                              <x-ui.icon-button label="Change {{ $item->name }}" icon="fa-pen"
                                                wire:click="editItem({{ $item->id }})" />
                              <x-ui.icon-button label="Remove {{ $item->name }}" icon="fa-xmark"
                                                wire:click="removeItem({{ $item->id }})"
                                                wire:confirm="Remove {{ $item->name }}? {{ $item->isProduct() ? 'It goes back on the shelf and comes off the bill.' : 'It comes off the bill.' }}"
                                                wire:loading.attr="disabled" wire:target="removeItem" />
                            @endif
                          </td>
                        </tr>
                      @endif
                    @endforeach
                  </tbody>
                </table>
              </div>
            @endif

            @if($this->editable)
              <form wire:submit="addItem" class="tb-ordm-add">
                {{-- Two choices is a switch, not a pair of cards. The cards
                     were as tall as the fields they sat above and said less. --}}
                <div class="tb-seg" role="group" aria-label="What kind of thing is being added">
                  <button type="button" @class(['tb-seg-btn', 'is-on' => $itemKind === 'service'])
                          wire:click="$set('itemKind', 'service')"
                          aria-pressed="{{ $itemKind === 'service' ? 'true' : 'false' }}">
                    <i class="fas fa-hand-holding-medical" aria-hidden="true"></i> Service
                  </button>
                  <button type="button" @class(['tb-seg-btn', 'is-on' => $itemKind === 'product'])
                          wire:click="$set('itemKind', 'product')"
                          aria-pressed="{{ $itemKind === 'product' ? 'true' : 'false' }}">
                    <i class="fas fa-pills" aria-hidden="true"></i> Product
                  </button>
                  <span class="tb-seg-hint">
                    {{ $itemKind === 'product'
                        ? 'Comes off the pharmacy shelf'
                        : 'From the price list' }}
                  </span>
                </div>

                <div class="tb-ordm-add-row">
                  <x-ui.field :label="$itemKind === 'product' ? 'Product' : 'Service'"
                              for="oi-what" :name="$itemKind === 'product' ? 'stock_item_id' : 'service_id'"
                              :hint="$this->stockOnHand ? $this->stockOnHand.' on the shelf' : null">
                    @if($itemKind === 'product')
                      <livewire:ui.select-search resource="stock-items" name="stock_item_id"
                                                 :selected="$stock_item_id" placeholder="Search the pharmacy…"
                                                 :key="'oi-prod-'.$orderId.'-'.$nonce" />
                    @else
                      <livewire:ui.select-search resource="services" name="service_id"
                                                 :selected="$service_id" placeholder="Search the price list…"
                                                 :key="'oi-svc-'.$orderId.'-'.$nonce" />
                    @endif
                  </x-ui.field>

                  <x-ui.field label="Qty" for="oi-qty" name="qty">
                    <input id="oi-qty" type="number" step="any" min="0.01" class="tb-input"
                           wire:model.live.debounce.300ms="qty">
                  </x-ui.field>

                  {{-- Never typed. Price × quantity, in bcmath, like every
                       other line the system prices. --}}
                  <div class="tb-ordm-total" aria-live="polite">
                    <span class="k">Total</span>
                    <b class="v"><x-ui.money :amount="$this->lineTotal" /></b>
                    @if($this->pickedPrice)
                      <span class="tb-ordm-calc">
                        <x-ui.money :amount="$this->pickedPrice" /> × {{ rtrim(rtrim($qty, '0'), '.') ?: '0' }}
                      </span>
                    @endif
                  </div>

                  <button type="submit" class="btn-tb btn-tb-primary tb-ordm-go"
                          wire:loading.attr="disabled" wire:target="addItem">
                    <span wire:loading.remove wire:target="addItem"><i class="fas fa-plus" aria-hidden="true"></i> Add</span>
                    <span wire:loading wire:target="addItem">Adding…</span>
                  </button>
                </div>

                {{-- Why this one, in the words of whoever is putting it on.
                     A charge nobody can explain is a charge somebody argues
                     about at the counter. --}}
                <x-ui.field label="Note" for="oi-note" name="itemNote">
                  <input id="oi-note" type="text" class="tb-input" maxlength="255"
                         placeholder="Why this is on the order — optional"
                         wire:model="itemNote">
                </x-ui.field>
              </form>
            @endif
          </section>

          {{-- ── What was found ────────────────────────────────────────
               Typed, autosaved. Files land on the private disk the moment
               they are dropped and come back only through the gated route. --}}
          <section class="tb-ordm-sec">
            <div class="tb-ordm-head">
              <h4 class="tb-label"><i class="fas fa-file-pen" aria-hidden="true"></i> Report</h4>
              {{-- The promise belongs here, not in a placeholder that vanishes
                   the moment it starts being true. --}}
              <span class="tb-ordm-saved" aria-live="polite">
                <span wire:loading wire:target="report,updatedReport"><i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Saving…</span>
                <span wire:loading.remove wire:target="report,updatedReport">
                  @if($reportSavedAt)
                    <i class="fas fa-check" aria-hidden="true"></i> Saved {{ $reportSavedAt }}
                  @elseif($this->editable)
                    Saves as you type
                  @endif
                </span>
              </span>
            </div>

            @if($this->editable)
              <textarea class="tb-textarea tb-ordm-report" rows="5" maxlength="20000"
                        placeholder="What was found, and what the next person needs to know."
                        wire:model.live.debounce.900ms="report"></textarea>
              @error('report')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
            @elseif($order->report)
              <div class="tb-vs-notes"><p>{{ $order->report }}</p></div>
            @else
              <x-ui.empty compact icon="fa-file-pen" message="No report was written." />
            @endif

            {{-- Drop zone. The input is the real one; the whole panel is
                 just a bigger target for it. --}}
            @if($this->editable)
              <div class="tb-drop" x-data="tdDrop($wire, 'files')"
                   x-on:dragover.prevent="over = true"
                   x-on:dragleave.prevent="over = false"
                   x-on:drop.prevent="drop($event)"
                   :class="{ 'is-over': over, 'is-busy': busy }">
                <input type="file" multiple class="sr-only" x-ref="input" wire:model="files"
                       accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx,.txt,.csv">
                <i class="fas fa-cloud-arrow-up" aria-hidden="true"></i>
                <p><button type="button" class="tb-linkish" x-on:click="$refs.input.click()">Choose files</button>
                   or drop them here</p>
                <span class="tb-drop-hint">Results, films, consent — up to 10 MB each, kept private</span>
                <div class="tb-drop-busy" wire:loading wire:target="files,updatedFiles">
                  <i class="fas fa-circle-notch fa-spin" aria-hidden="true"></i> Uploading…
                </div>
              </div>
              @error('files.*')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
              @error('files')<div class="tb-field-error" role="alert">{{ $message }}</div>@enderror
            @endif

            @if($order->attachments->isNotEmpty())
              <ul class="tb-files">
                @foreach($order->attachments as $file)
                  <li wire:key="att-{{ $file->id }}">
                    <i class="fas {{ $file->isImage() ? 'fa-image' : 'fa-file-lines' }}" aria-hidden="true"></i>
                    <a href="{{ route('admin.orders.attachments.download', [$order, $file]) }}">{{ $file->original_name }}</a>
                    <span class="meta">{{ $file->readableSize() }} · {{ $file->uploader?->name ?? 'Someone' }}</span>
                    @if($this->editable)
                      <x-ui.icon-button label="Remove {{ $file->original_name }}" icon="fa-xmark"
                                        wire:click="removeAttachment({{ $file->id }})"
                                        wire:confirm="Remove {{ $file->original_name }}? This deletes the file."
                                        wire:loading.attr="disabled" wire:target="removeAttachment" />
                    @endif
                  </li>
                @endforeach
              </ul>
            @endif
          </section>
        </div>

        <div class="tb-modal-foot tb-ord-foot">
          <div class="tb-ord-acts-left">
            <button type="button" class="btn-tb btn-tb-ghost" wire:click="close">Close</button>

            {{-- Destructive, so it sits away from the thing people mean to
                 press and does not wear the same colour as it. --}}
            @if($this->canManage && $order->status->isOpen())
              <button type="button" class="btn-tb btn-tb-ghost tb-ord-kill" wire:click="askCancel">
                <i class="fas fa-ban" aria-hidden="true"></i> Cancel order
              </button>
            @endif
          </div>

          <div class="tb-ord-acts">
            @if($this->canManage)
              {{-- One primary. "In progress" is a lesser step than finishing,
                   and two blue buttons side by side make the reader choose
                   between them rather than read them. --}}
              @foreach($order->status->transitionsTo() as $next)
                @continue($next === \App\Enums\OrderStatus::Cancelled)
                @php($isDone = $next === \App\Enums\OrderStatus::Completed)

                @if($isDone && ! $this->completable['ready'])
                  {{-- Said, not discovered. Finishing an order with nothing on
                       it would tell the next reader that work happened and
                       leave no trace of what. Cancelling is still there. --}}
                  <span class="tb-gate-block">
                    <i class="fas fa-lock" aria-hidden="true"></i> {{ $this->completable['blocker'] }}
                  </span>
                @else
                  <button type="button"
                          @class(['btn-tb', 'btn-tb-primary' => $isDone, 'btn-tb-ghost' => ! $isDone])
                          wire:click="move('{{ $next->value }}')"
                          wire:loading.attr="disabled" wire:target="move">
                    <i class="fas {{ $isDone ? 'fa-check' : 'fa-play' }}" aria-hidden="true"></i>
                    {{ $isDone && $this->stay?->status->isActive()
                        ? 'Discharge patient'
                        : 'Mark '.strtolower($next->label()) }}
                  </button>
                @endif
              @endforeach
            @endif
          </div>
        </div>
      @endif
    @endif
  </x-ui.modal>
</div>
