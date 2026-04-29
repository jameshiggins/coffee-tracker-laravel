@component('mail::message')
# Beans you've been waiting for

A bean on your wishlist is back in stock — grab it before it sells out
again.

@foreach($coffees as $c)
**{{ $c['name'] }}** — {{ $c['roaster_name'] }}
[View on the directory]({{ $c['frontend_url'] }})

@endforeach

---

You're receiving this because you wishlisted these beans on Specialty
Coffee Roasters. If you'd rather not get restock alerts,
[unsubscribe here]({{ $unsubscribeUrl }}).

Thanks,<br>
Specialty Coffee Roasters
@endcomponent
