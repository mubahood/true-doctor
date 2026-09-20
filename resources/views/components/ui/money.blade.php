@props(['amount'])
<span {{ $attributes->merge(['class' => 'mono']) }}>{{ \App\Support\HospitalSettings::money($amount) }}</span>
