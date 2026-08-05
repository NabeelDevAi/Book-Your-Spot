<footer class="app-footer">
    <div class="container-box app-footer-inner">
        <span>&copy; {{ now()->year }} Venu365 — Karachi</span>

        <div class="footer-links">
            <span class="text-muted">Pay at the venue · No online payment</span>
            @guest
                <a href="{{ route('register') }}" class="link">List your venue</a>
            @endguest
        </div>
    </div>
</footer>
