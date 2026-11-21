@php
    $locale = app()->getLocale();

    $faqs = [
        'ar' => [
            [
                'q' => 'كيفاش كنخلص طلبيتي؟ (طريقة الدفع)',
                'a' => 'الدفع عند الاستلام 100% (Cash on Delivery). ما كتخلص حتى كتوصلك الأمانة ديالك حتى لباب الدار، كتفحصها وتتأكد منها عاد كتخلص موزع التوصيل.',
                'icon' => 'cash',
            ],
            [
                'q' => 'شحال كياخد التوصيل من وقت وفين كتوصلو؟',
                'a' => 'التوصيل سريع جداً، كياخد ما بين 24 إلى 48 ساعة فقط. كنوصلو لجميع المدن والمناطق في المغرب مباشرة حتى للعنوان أو مقر العمل ديالك.',
                'icon' => 'truck',
            ],
            [
                'q' => 'واش نقدر نحل الكولية ونشوف المنتج قبل ما نخلص؟',
                'a' => 'نعم، بكل تأكيد! عندك كامل الحق تفتح الطرد وتفحص المنتج وتتأكد من جودته ومطابقته لطلبك بحضور موزع التوصيل قبل أداء المبلغ.',
                'icon' => 'box',
            ],
            [
                'q' => 'واش كاين ضمان واستبدال فـ حالة وجود أي مشكل؟',
                'a' => 'نعم، كنوفروا ضمان الجودة الكامل مع إمكانية استبدال أو استرجاع المنتج بكل سهولة وسرعة خلال 7 أيام في حالة وجود أي عيب مصنعي أو عدم الرضا.',
                'icon' => 'shield',
            ],
            [
                'q' => 'كيفاش نتواصل معاكم إلا عندي شي استفسار إضافي؟',
                'a' => 'فريق خدمة الزبناء ديالنا متواجد ومستعد لمساعدتكم ومتابعة طلبياتكم مباشرة عبر الواتساب على مدار الأسبوع.',
                'icon' => 'chat',
            ],
        ],
        'fr' => [
            [
                'q' => 'Comment s\'effectue le paiement ?',
                'a' => 'Le paiement s\'effectue à 100% à la livraison (Cash on Delivery). Vous ne payez rien d\'avance : vous payez en espèces au livreur uniquement après réception et vérification de votre commande.',
                'icon' => 'cash',
            ],
            [
                'q' => 'Quels sont les délais et zones de livraison ?',
                'a' => 'La livraison est rapide en 24 à 48 heures partout au Maroc, directement à votre domicile ou lieu de travail.',
                'icon' => 'truck',
            ],
            [
                'q' => 'Puis-je vérifier le colis avant de payer ?',
                'a' => 'Oui, absolument ! Vous avez le droit d\'ouvrir le colis et d\'inspecter le produit en présence du livreur avant tout règlement.',
                'icon' => 'box',
            ],
            [
                'q' => 'Y a-t-il une garantie et un service d\'échange ?',
                'a' => 'Oui, tous nos articles bénéficient d\'une garantie qualité avec possibilité d\'échange ou de retour sous 7 jours en cas de défaut ou de non-conformité.',
                'icon' => 'shield',
            ],
            [
                'q' => 'Comment contacter le service client ?',
                'a' => 'Notre équipe est disponible 7j/7 pour répondre à toutes vos questions et suivre votre commande en un clic sur WhatsApp.',
                'icon' => 'chat',
            ],
        ],
        'en' => [
            [
                'q' => 'How do I pay for my order? (Payment Method)',
                'a' => '100% Cash on Delivery (COD). You pay nothing in advance; payment is made in cash to the courier only upon receiving and inspecting your package.',
                'icon' => 'cash',
            ],
            [
                'q' => 'What are the delivery times and coverage?',
                'a' => 'Fast delivery within 24 to 48 hours to all cities and regions across Morocco, right to your doorstep or workplace.',
                'icon' => 'truck',
            ],
            [
                'q' => 'Can I inspect the parcel before paying?',
                'a' => 'Yes, absolutely! You have the full right to open and inspect the product in the presence of the delivery agent before making payment.',
                'icon' => 'box',
            ],
            [
                'q' => 'Is there a warranty and return/exchange policy?',
                'a' => 'Yes, we provide a complete quality guarantee with an easy 7-day exchange or return policy if there is any defect or dissatisfaction.',
                'icon' => 'shield',
            ],
            [
                'q' => 'How can I contact customer support?',
                'a' => 'Our customer support team is available 7 days a week to answer questions and assist you directly via WhatsApp.',
                'icon' => 'chat',
            ],
        ],
    ];

    $items = $faqs[$locale] ?? $faqs['ar'];

    $whatsappPhone = core()->getConfigData('general.general.whatsapp.phone_number');
    $cleanPhone = preg_replace('/[^0-9]/', '', (string) $whatsappPhone);
@endphp

<div class="my-6 space-y-3.5">
    @foreach ($items as $index => $faq)
        <details class="group rounded-2xl border border-zinc-200 bg-white p-4 transition-all duration-200 hover:border-zinc-300 hover:shadow-xs open:border-emerald-200 open:bg-emerald-50/20 open:shadow-xs">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-zinc-900 select-none">
                <span class="flex items-center gap-3 text-base max-md:text-sm">
                    @if ($faq['icon'] === 'cash')
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </span>
                    @elseif ($faq['icon'] === 'truck')
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 17a2 2 0 100 4 2 2 0 000-4zm10 0a2 2 0 100 4 2 2 0 000-4zM3 4h2l2.4 10.4a1 1 0 001 .6H17a1 1 0 001-.8l1.6-6.2H6" />
                            </svg>
                        </span>
                    @elseif ($faq['icon'] === 'box')
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </span>
                    @elseif ($faq['icon'] === 'shield')
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-purple-100 text-purple-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </span>
                    @else
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </span>
                    @endif

                    <span>{{ $faq['q'] }}</span>
                </span>

                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 transition-transform duration-200 group-open:rotate-180 group-open:bg-emerald-100 group-open:text-emerald-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                    </svg>
                </span>
            </summary>

            <div class="mt-3.5 pr-11 pl-4 text-sm leading-relaxed text-zinc-600 max-md:pr-4">
                {{ $faq['a'] }}
            </div>
        </details>
    @endforeach

    <!-- Help WhatsApp Banner (only when the admin WhatsApp number is set) -->
    @if ($cleanPhone)
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-gradient-to-r from-emerald-50 to-teal-50/40 p-4">
        <div class="flex items-center gap-3">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#25D366] text-white shadow-sm">
                <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24">
                    <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766 0-3.187-2.59-5.771-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.354.101.174.449.741.964 1.201.662.591 1.221.774 1.394.86s.275.072.376-.043c.101-.116.433-.506.549-.68.116-.173.231-.145.39-.087s1.011.477 1.184.564.289.13.332.202c.045.072.045.419-.1.824zm-3.423-10.416c-5.522 0-10 4.478-10 10 0 1.772.463 3.497 1.341 5.022l-1.425 5.218 5.342-1.401c1.468.802 3.125 1.223 4.742 1.223 5.522 0 10-4.477 10-10 0-5.521-4.478-10-10-10z"/>
                </svg>
            </span>
            <div>
                <p class="text-sm font-bold text-zinc-900">
                    {{ $locale === 'ar' ? 'عندك شي سؤال آخر على هاد المنتج؟' : ($locale === 'fr' ? 'Une autre question sur ce produit ?' : 'Have another question about this product?') }}
                </p>
                <p class="text-xs text-zinc-600">
                    {{ $locale === 'ar' ? 'تواصل معنا مباشرة عبر الواتساب ونجاوبوك فالحين' : ($locale === 'fr' ? 'Contactez-nous directement sur WhatsApp pour une réponse immédiate' : 'Chat directly with us on WhatsApp for instant assistance') }}
                </p>
            </div>
        </div>

        <a
            href="https://wa.me/{{ $cleanPhone }}?text={{ rawurlencode($locale === 'ar' ? 'السلام عليكم، عندي استفسار على هاد المنتج: ' . $product->name . ' - ' . route('shop.product_or_category.index', $product->url_key) : 'Hello, I have a question about: ' . $product->name) }}"
            target="_blank"
            rel="noopener noreferrer"
            class="inline-flex items-center gap-2 rounded-xl bg-[#25D366] px-4 py-2 text-xs font-bold text-white shadow-xs transition-all hover:bg-[#1da851] active:scale-95"
        >
            <span>WhatsApp</span>
            <svg class="h-3.5 w-3.5 rtl:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
            </svg>
        </a>
    </div>
    @endif
</div>
