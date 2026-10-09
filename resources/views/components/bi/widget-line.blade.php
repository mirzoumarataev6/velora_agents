@props(['widget', 'result'])

{{-- Change over time, unfilled. See widget-series-chart for everything. --}}
<x-bi.widget-series-chart :widget="$widget" :result="$result" :fill="false" />
