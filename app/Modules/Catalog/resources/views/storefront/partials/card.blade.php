@php
    $locale = app()->getLocale();
    $status = $card->type === 'physical' ? ($card->inStock ? null : __('platform::ui.out_of_stock')) : null;
@endphp
<x-platform::product-card
    class="h-full"
    :name="$card->name" :url="url('/'.$locale.'/p/'.rawurlencode($card->slug))"
    :image="$card->image" :srcset="$card->srcset" :width="$card->imageWidth ?? 320" :height="$card->imageHeight ?? 320"
    :price="$card->price" :compare-at="$card->compareAt"
    :badge="$card->onSale ? __('platform::ui.sale') : ($card->type === 'service' ? __('platform::ui.service') : null)"
    :status="$status" />
