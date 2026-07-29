{{--
    Stylesheet and script tags for the build-less pipeline. There is no bundler:
    app.css @imports its partials natively and app.js is a native ES module, so
    both are served exactly as they are written on disk.

    The fonts are preloaded because without a bundler they sit three levels deep
    in the discovery chain (app.css -> _fonts.css -> woff2). font-display: swap
    already stops that blocking render; the preload stops the swap being visible.
--}}

<link rel="preload" as="font" type="font/woff2" crossorigin
      href="{{ asset('assets/fonts/bricolage-grotesque-variable.woff2') }}">
<link rel="preload" as="font" type="font/woff2" crossorigin
      href="{{ asset('assets/fonts/inter-variable.woff2') }}">

<link rel="stylesheet" href="{{ \App\Support\Assets::url('assets/css/app.css') }}">

{{-- type="module" is deferred by definition, so this is safe in <head>. --}}
<script type="module" src="{{ \App\Support\Assets::url('assets/js/app.js') }}"></script>
