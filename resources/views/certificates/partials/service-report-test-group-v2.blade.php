<div class="test-group">
    <div class="test-group-title">{{ $group['label'] }}</div>
    <table class="data test-table">
        <thead><tr>@foreach ($group['columns'] as $column)<th>{{ $column['label'] }}@if ($column['unit'] !== '')<span class="unit">({{ $column['unit'] }})</span>@endif</th>@endforeach</tr></thead>
        <tbody>@foreach ($group['rows'] as $row)<tr>@foreach ($row['cells'] as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody>
    </table>
    @if ($group['remarks'] !== '')<div class="test-note">{{ $group['remarks'] }}</div>@endif
    @if ($group['tolerance'] !== null)<div class="test-note">Correction Tolerance = {{ $group['tolerance'] }}</div>@endif
</div>
