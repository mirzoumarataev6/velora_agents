@props(['widget', 'result'])

{{-- The same chart with the region under the curve painted — which reads as
     volume rather than as a rate, and is why it is a separate choice in the
     editor rather than a styling option. --}}
<x-bi.widget-series-chart :widget="$widget" :result="$result" :fill="true" />
