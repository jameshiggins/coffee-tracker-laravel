@extends('layouts.app')

@section('title', 'Moderation queue')

@section('content')
<div class="admin-content">
    <a href="{{ route('admin.roasters.index') }}" class="back-link">← Back to admin</a>
    <h2 style="margin-bottom: 15px; color: #8B4513;">Moderation queue</h2>

    <div style="margin-bottom: 20px; display: flex; gap: 10px;">
        <a href="{{ route('admin.moderation.index', ['tab' => 'flagged']) }}"
           class="btn {{ $tab === 'flagged' ? 'btn-primary' : 'btn-secondary' }}">
            Flagged ({{ $counts['flagged'] }})
        </a>
        <a href="{{ route('admin.moderation.index', ['tab' => 'hidden']) }}"
           class="btn {{ $tab === 'hidden' ? 'btn-primary' : 'btn-secondary' }}">
            Hidden ({{ $counts['hidden'] }})
        </a>
    </div>

    @if($tastings->isEmpty())
        <div class="empty-state">
            <h3>Nothing to review</h3>
            <p>{{ $tab === 'hidden' ? 'No tastings are currently hidden.' : 'No flagged tastings — all clean.' }}</p>
        </div>
    @else
        <div class="table-container" style="margin: 0;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ $tab === 'hidden' ? 'Hidden' : 'Flagged' }}</th>
                        <th>User</th>
                        <th>Coffee</th>
                        <th>Rating</th>
                        <th>Notes</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($tastings as $t)
                        <tr>
                            <td style="white-space: nowrap; font-size: 12px; color: #666;">
                                {{ ($tab === 'hidden' ? $t->deleted_at : $t->flagged_at)?->diffForHumans() }}
                            </td>
                            <td>
                                @if($t->user)
                                    <a href="/u/{{ $t->user->display_name }}" target="_blank" style="color: #007bff;">
                                        {{ $t->user->display_name ?? $t->user->email }}
                                    </a>
                                @else
                                    <span style="color: #999;">(deleted)</span>
                                @endif
                            </td>
                            <td>
                                @if($t->coffee)
                                    {{ $t->coffee->roaster->name ?? '?' }} — {{ $t->coffee->name }}
                                @else
                                    <span style="color: #999;">(removed)</span>
                                @endif
                            </td>
                            <td>{{ $t->rating ? $t->rating . '/10' : '—' }}</td>
                            <td style="max-width: 400px; font-size: 13px; color: #333;">
                                {{ \Illuminate\Support\Str::limit($t->notes, 200) }}
                            </td>
                            <td>
                                <div class="action-btns">
                                    @if($tab === 'hidden')
                                        <form method="POST" action="{{ route('admin.moderation.restore', $t->id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-small btn-secondary">Restore</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('admin.moderation.dismiss', $t->id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-small btn-secondary">Dismiss flag</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.moderation.hide', $t->id) }}"
                                              onsubmit="return confirm('Hide this tasting from public surfaces?');">
                                            @csrf
                                            <button type="submit" class="btn btn-small btn-danger">Hide</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
