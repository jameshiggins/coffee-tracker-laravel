<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'BC Coffee Roasters Price Tracker')</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #8B4513 0%, #D2691E 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #6F4E37 0%, #8B4513 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 2.5em; margin-bottom: 10px; text-shadow: 2px 2px 4px rgba(0,0,0,0.3); }
        .header p { font-size: 1.2em; opacity: 0.9; }
        .header-nav { margin-top: 12px; }
        .header-nav a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 14px;
            padding: 6px 16px;
            border-radius: 6px;
            transition: all 0.3s;
        }
        .header-nav a:hover { background: rgba(255,255,255,0.15); color: white; }
        .header-nav a.active { background: rgba(255,255,255,0.2); color: white; }
        .controls {
            padding: 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        .search-box { flex: 1; min-width: 200px; }
        .search-box input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        .search-box input:focus { outline: none; border-color: #8B4513; }
        .filter-controls { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .filter-controls select {
            padding: 10px 14px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        .filter-controls select:focus { outline: none; border-color: #8B4513; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #8B4513; color: white; }
        .btn-primary:hover { background: #6F4E37; transform: translateY(-2px); }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-secondary:hover { background: #5a6268; }
        .btn-small { padding: 6px 12px; font-size: 12px; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-danger:hover { background: #c82333; }
        .stats {
            padding: 20px;
            background: #f1f3f4;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 2em; font-weight: bold; color: #8B4513; }
        .stat-label { color: #666; margin-top: 5px; }
        .table-container { overflow-x: auto; margin: 20px; }
        .coffee-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .coffee-table th {
            background: #8B4513;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .coffee-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .coffee-table tr:hover { background: #f8f9fa; }
        .region-badge {
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            color: white;
            white-space: nowrap;
        }
        .region-victoria { background: #17a2b8; }
        .region-vancouver { background: #28a745; }
        .region-interior { background: #ffc107; color: #333; }
        .region-kootenays { background: #dc3545; }
        .region-okanagan { background: #6f42c1; }
        .price-cell { font-weight: bold; font-size: 1.1em; }
        .price-good { color: #28a745; }
        .price-average { color: #daa520; }
        .price-expensive { color: #dc3545; }
        .sort-header { cursor: pointer; user-select: none; }
        .sort-header:hover { background: #6F4E37; }
        .sort-header a { color: white; text-decoration: none; display: block; }
        .sort-arrow { margin-left: 5px; font-size: 12px; }
        .success-banner {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px 20px;
            text-align: center;
        }
        .empty-state { text-align: center; padding: 60px 20px; color: #666; }
        .empty-state h3 { margin-bottom: 10px; color: #8B4513; }

        /* Admin styles */
        .admin-content { padding: 20px; }
        .admin-form { max-width: 700px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 500; color: #333; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 8px; font-size: 14px;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: #8B4513;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-section { border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px; }
        .form-section h3 { color: #8B4513; margin-bottom: 12px; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: normal; }
        .checkbox-label input[type=checkbox] { width: auto; }
        .error-list { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .error-list ul { list-style: disc; margin-left: 20px; }
        .back-link { color: #8B4513; text-decoration: none; font-size: 14px; display: inline-block; margin-bottom: 15px; }
        .back-link:hover { text-decoration: underline; }
        .admin-table { width: 100%; border-collapse: collapse; }
        .admin-table th { background: #8B4513; color: white; padding: 12px 15px; text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        .admin-table td { padding: 10px 15px; border-bottom: 1px solid #eee; }
        .admin-table tr:hover { background: #f8f9fa; }
        .inline-coffees { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px 15px; background: #fafafa; }
        .coffee-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: white; border: 1px solid #ddd; border-radius: 8px;
            padding: 4px 10px; font-size: 12px;
        }
        .coffee-chip a { color: #007bff; text-decoration: none; }
        .coffee-chip a:hover { text-decoration: underline; }
        .coffee-chip form { display: inline; }
        .coffee-chip .delete-x { background: none; border: none; color: #dc3545; cursor: pointer; font-size: 14px; padding: 0 2px; }
        .action-btns { display: flex; gap: 6px; justify-content: flex-end; }

        @media (max-width: 768px) {
            .controls { flex-direction: column; align-items: stretch; }
            .filter-controls { justify-content: center; }
            .stats { grid-template-columns: 1fr; }
            .coffee-table { font-size: 14px; }
            .coffee-table th, .coffee-table td { padding: 8px; }
            .header h1 { font-size: 1.8em; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>☕ BC Coffee Roasters</h1>
            <p>Track single-origin coffee prices across British Columbia</p>
            <div class="header-nav">
                <a href="{{ route('roasters.index') }}" class="{{ request()->routeIs('roasters.index') ? 'active' : '' }}">Price Tracker</a>
                <a href="{{ route('admin.roasters.index') }}" class="{{ request()->routeIs('admin.*') ? 'active' : '' }}">Admin</a>
            </div>
        </div>

        @if(session('success'))
            <div class="success-banner">{{ session('success') }}</div>
        @endif

        @yield('content')
    </div>
</body>
</html>
