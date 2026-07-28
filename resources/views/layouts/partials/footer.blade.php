<footer class="app-footer">
    <div class="container app-footer-inner">
        <span>&copy; {{ now()->year }} BookYourSpot — Karachi</span>

        <div class="footer-links">
            <span class="text-muted">Pay at the venue · No online payment</span>
            @guest
                <a href="{{ route('register') }}" class="link">List your venue</a>
            @endguest
        </div>
    </div>
</footer>
