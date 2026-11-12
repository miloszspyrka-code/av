/* Alpha Vault — dane statyczne, kalendarz, generator danych demo */
(function () {
  'use strict';
  const AV = window.AV;

  AV.LIVES = [
    ['Pn', 'Analiza rynku na tydzień', 'Marcin · BTC, ETH i plan na tydzień'],
    ['Wt', 'Live z psycholożką', 'Marysia · mental i emocje'],
    ['Śr', 'Q&A: pytaj o wszystko', 'Albert i Hubert'],
    ['Czw', 'Mental i pieniądze', 'Albert · dyscyplina i kapitał'],
    ['Pt', 'Analiza rynku na nowy tydzień', 'Marcin · plan na weekend'],
    ['Sb', 'Blockchain i projekty', 'Hubert · fundamenty'],
    ['Nd', 'Podsumowanie tygodnia', 'cała trójka']
  ];

  AV.INST = {
    crypto: [
      ['BTC/USDT', 69120], ['ETH/USDT', 3410], ['SOL/USDT', 164.9], ['XRP/USDT', 0.598],
      ['BNB/USDT', 588], ['DOGE/USDT', 0.179], ['LINK/USDT', 14.2], ['AVAX/USDT', 27.4]
    ],
    fx: [
      ['EUR/USD', 1.0842, 'fx', 0.0001, 10], ['GBP/USD', 1.2955, 'fx', 0.0001, 10],
      ['USD/JPY', 152.4, 'fx', 0.01, 'jpy'], ['AUD/USD', 0.6585, 'fx', 0.0001, 10],
      ['USD/CHF', 0.8712, 'fx', 0.0001, 'quote'], ['USD/CAD', 1.3905, 'fx', 0.0001, 'quote'],
      ['NZD/USD', 0.5978, 'fx', 0.0001, 10], ['EUR/GBP', 0.8369, 'fx', 0.0001, 'gbp'],
      ['NAS100 · Nasdaq 100', 21480, 'ind', 1, 1], ['US500 · S&P 500', 6215, 'ind', 1, 1],
      ['US30 · Dow Jones', 43950, 'ind', 1, 1], ['GER40 · DAX 40', 19620, 'ind', 1, 1],
      ['UK100 · FTSE 100', 8240, 'ind', 1, 1], ['JPN225 · Nikkei 225', 39150, 'ind', 1, 1],
      ['XAUUSD · Złoto', 2742, 'met', 0.01, 1], ['XAGUSD · Srebro', 32.6, 'met', 0.001, 5],
      ['OIL · Ropa', 71.6, 'met', 0.01, 10]
    ]
  };

  AV.TICKER = [
    ['BTC', 69120, 1.8], ['ETH', 3410, -0.6], ['SOL', 164.9, -2.1], ['NAS100', 21480, 0.4],
    ['US500', 6215, 0.2], ['XAUUSD', 2742, 0.7], ['EUR/USD', 1.0842, -0.1], ['OIL', 71.6, 1.2]
  ];

  AV.SYM = { USD: '$', EUR: '€', GBP: '£', JPY: '¥', AUD: 'A$', CAD: 'C$', CHF: '₣', NZD: 'NZ$', CNY: '¥', PLN: 'zł' };

  AV.TIPS = [
    ['MM', 'Ryzykujesz maksymalnie 1% kapitału. Przy {kapital} USDT to {ryzyko1} USDT na transakcję. Ani dolara więcej.'],
    ['MM', 'Najpierw stop loss, potem wielkość pozycji. Nigdy odwrotnie.'],
    ['MM', 'Dźwignia nie zwiększa szans. Zwiększa tylko to, ile stracisz, jeśli się mylisz.'],
    ['MM', 'R:R poniżej 1:2? Potrzebujesz wtedy bardzo wysokiej skuteczności, żeby wyjść na plus. Popraw cel albo odpuść.'],
    ['MM', 'SL przesuwasz tylko w stronę zysku. Nigdy dalej od ceny.'],
    ['MM', 'Wielkość pozycji liczysz w Kalkulatorze Alpha, nie na oko.'],
    ['Mental', 'Wdech na 4 sekundy, zatrzymanie na 4, wydech na 4. Dopiero teraz klikasz.'],
    ['Mental', 'Czy to wejście miałoby sens, gdyby cena stała w miejscu od godziny? Jeśli nie, to emocja, a nie setup.'],
    ['Mental', 'Wyobraź sobie, że SL właśnie się zrealizował. Jeśli to boli, pozycja jest za duża.'],
    ['Mental', 'Ostatnia transakcja nie ma nic wspólnego z tą. Rynek nie pamięta, Ty też nie musisz.'],
    ['Mental', 'Nie musisz łapać każdego ruchu. Wystarczy łapać swoje.'],
    ['Mental', 'Szybsze bicie serca to sygnał emocji, a nie setupu. Odczekaj 2 minuty.'],
    ['Mental', 'Plan powstaje rano, w spokojnej głowie. Teraz tylko go wykonujesz.'],
    ['Forma', 'Gotowość dziś {gotowosc}/100. Przy wyniku poniżej 60 grasz połową pozycji.'],
    ['Rynek', 'Sprawdź kalendarz: czy w ciągu 15 minut nie ma danych ★★★? Najbliższe: {event}, {eventWhen} {eventTime}.'],
    ['Rynek', 'Czy wejście jest na poziomie z planu dnia? Jeśli nie, to nie jest Twój trade.']
  ];

  AV.EMOTIONS = ['Spokój', 'Pewność', 'Skupienie', 'Nuda', 'Zmęczenie', 'Strach', 'FOMO', 'Chciwość', 'Złość'];
  AV.ERRORS = ['Wejście za wcześnie', 'Gonienie ceny', 'Przesunięty SL', 'Bez SL', 'Za duża pozycja', 'Za wczesne wyjście', 'Wejście po stracie', 'Handel przy danych', 'Brak setupu'];
  AV.SESSIONS = ['Azja', 'Londyn', 'Nowy Jork', 'Po godzinach'];
  AV.GRADES = ['A', 'B', 'C'];

  AV.MENTOR = [
    {
      q: 'Dziś strata i chęć odkuwania się',
      a: [
        'Rozumiem, każdy to zna. Ale spójrz na swoje liczby: po stracie pojawiło się {poStracie} pozycji na minusie, razem {poStracieWynik}. Odkuwanie się to nie strategia, to emocja z dźwignią.<br><br><b class="al">Krok:</b> zamknij platformę na 30 minut i zapisz w dzienniku, co teraz czujesz.',
        '{imie}, rynek nie wie o stracie. I nie odda Ci tego tylko dlatego, że bardzo chcesz. Masz dziś {straty} z {limit} strat. Ten limit powstał w spokojnej głowie właśnie na taki moment.<br><br><b class="al">Krok:</b> zaufaj tamtej, spokojnej wersji siebie.',
        'Myśl „muszę to odrobić” kosztowała Cię już {poStracieWynik}. Najlepsi traderzy po stracie robią najmniej, nie najwięcej.<br><br><b class="al">Krok:</b> wstań od biurka na 10 minut. Wróć tylko do dziennika, nie do wykresu.',
        'Dziś jesteś na {wynikDzis}. To boli, ale to nie jest Twój wynik miesiąca. Ten zależy od tego, co zrobisz w najbliższej godzinie.<br><br><b class="al">Krok:</b> zero nowych pozycji do jutra. Jutro wracasz z planem dnia.',
        'Gotowość {gotowosc}/100 i strata. Gorszego zestawu do odrabiania nie ma.<br><br><b class="al">Krok:</b> dziś trenujesz cierpliwość, nie wejścia.',
        'Amator po stracie zwiększa pozycję. Zawodowiec ją zmniejsza albo odpuszcza. Twoje dane mówią jasno: wejścia po stracie mają u Ciebie najgorszą skuteczność ze wszystkich.<br><br><b class="al">Krok:</b> jeśli już musisz, to połowa pozycji i tylko setup z planu.'
      ]
    },
    {
      q: 'Mam FOMO. Wejść czy nie?',
      a: [
        'Krótko: nie teraz. Przy FOMO masz {fomoWr}% skuteczności, ogólnie {wr}%. To {fomoN} transakcji i {fomoWynik}.<br><br><b class="al">Krok:</b> ustaw alert na poziomie z planu i odejdź. Jak cena tam wróci, wejdziesz na swoich warunkach.',
        'Jeśli ruch poszedł bez Ciebie, to nie był Twój ruch. Rynek da jutro kolejny.<br><br><b class="al">Krok:</b> 15 minut przerwy, potem checklista. Jeśli wejście nie jest na poziomie z planu, odpuszczasz.',
        'FOMO to uczucie, że coś Ci ucieka. A naprawdę ucieka Ci tylko kapitał: {fomoWynik} z {fomoN} takich wejść.<br><br><b class="al">Krok:</b> zapisz w dzienniku, co widzisz na wykresie, i wróć za kwadrans.',
        'Jedno pytanie: czy to wejście miałoby sens, gdyby cena stała w miejscu od godziny? Jeśli nie, to wchodzisz w emocję, a nie w setup.<br><br><b class="al">Krok:</b> sprawdź punkt 2 checklisty: „poziom z planu”.',
        'Najdroższe zdanie w tradingu to „jeszcze zdążę”. U Ciebie kosztowało już {fomoWynik}.<br><br><b class="al">Krok:</b> jeśli bardzo chcesz, to maksymalnie połowa pozycji i SL przed wejściem.',
        'Dobrze, że to zostało zauważone. Większość ludzi klika, zanim w ogóle nazwie to uczucie.<br><br><b class="al">Krok:</b> zaznacz FOMO w check-inie i odczekaj 15 minut. Jeśli setup dalej będzie ważny, wtedy wracamy do tematu.'
      ]
    },
    {
      q: 'Co najbardziej psuje moje wyniki?',
      a: [
        'Trzy rzeczy, w tej kolejności: transakcje z oceną C ({cN} sztuk, {cWynik}), wejścia po stracie ({poStracieWynik}) i granie {godz} ({godzWr}% skuteczności).<br><br><b class="al">Krok:</b> wytnij tylko pierwszy punkt, a wynik rośnie o ponad 130.',
        'Na pewno nie strategia. Twoje transakcje A mają {aWr}% skuteczności. Wynik psują dni, w które lepiej nie grać: po słabym śnie, po stracie i wieczorem.<br><br><b class="al">Krok:</b> przy gotowości poniżej 60 grasz połową pozycji.',
        'Najwięcej zabiera Ci {para}: {paraWynik}. Najwięcej daje {dobraPara}: {dobraWynik}.<br><br><b class="al">Krok:</b> przez 2 tygodnie zero {para}. Różnicę zobaczysz na krzywej kapitału.',
        'Pomijanie checklisty. Z pełną masz {clFull}% skuteczności, bez niej {clNo}%. To nie drobiazg, to prawie dwa razy lepszy wynik.<br><br><b class="al">Krok:</b> żadnej transakcji poniżej 6/7.',
        'Pora dnia. {godz} masz {godzWr}% skuteczności. Rano jesteś zupełnie innym traderem.<br><br><b class="al">Krok:</b> ustaw w zasadach „nie handluję po 21:00”. Przypomnę Ci o tym.',
        'Brak planu. W dni z zatwierdzonym planem jesteś średnio na plusie, bez planu na minusie. Dziś zgodnie z planem: {planZgodne}.<br><br><b class="al">Krok:</b> plan dnia przed pierwszą transakcją. Zawsze.'
      ]
    },
    {
      q: 'Oceń mój dzień',
      a: [
        'Dziś: {tr} transakcje, wynik {wynikDzis}, zgodnie z planem {planZgodne}. Poranny trade na {dobraPara} był wzorowy, dwa kolejne to emocje.<br><br><b class="al">Ocena dnia: C+.</b> Plan dobry, wykonanie rozjechało się po pierwszej stracie.',
        'Zacznę od dobrego: pierwsza transakcja miała pełną checklistę i SL. Potem przyszła strata i w 12 minut pojawiły się dwie decyzje, których rano nikt by nie podpisał.<br><br><b class="al">Lekcja:</b> po stracie przerwa, nie kolejny trade.',
        'Liczby: {wynikDzis}, {straty} straty, gotowość {gotowosc}. Ale najważniejsze jest co innego: tryb ochronny zadziałał. To dobry dzień dla dyscypliny, nawet jeśli słaby dla konta.',
        'Gdyby dzień skończył się po pierwszej transakcji, byłby świetny. Wszystko, co przyszło potem, to próba odrobienia.<br><br><b class="al">Na jutro:</b> po pierwszej stracie robisz przerwę.',
        'Szczerze: 4/10. Plan był, ale przegrał z emocjami. Dobra wiadomość: wiesz dokładnie, co poszło nie tak, bo masz to w dzienniku.<br><br><b class="al">Krok:</b> dopisz do planu na jutro „po stracie 30 minut przerwy”.',
        'Plusy: plan dnia, SL na pierwszej pozycji, uzupełniony dziennik. Minusy: dwie transakcje C, w tym jedna bez SL.<br><br><b class="al">Bilans:</b> proces na 60%, wynik {wynikDzis}. Proces poprawisz szybciej niż wynik.'
      ]
    },
    {
      q: 'Co dziś dzieje się na rynku?',
      a: [
        'Najważniejsze teraz: <b>{event}</b> ({eventWhen}, {eventTime}). W minutach publikacji zmienność skacze, a spready się rozszerzają.<br><br><b class="al">Krok:</b> 15 minut przed i po publikacji nie otwierasz nowych pozycji.',
        'W kalendarzu: <b>{event}</b>, {eventWhen} o {eventTime}. Twoje dane: w dni z ważnymi publikacjami masz {macroWynik}.<br><br><b class="al">Krok:</b> tego dnia grasz połową pozycji.',
        'Patrz na dolara. Przed <b>{event}</b> ({eventWhen}, {eventTime}) rynek często stoi, a potem rusza gwałtownie w jedną stronę. Nie zgaduj kierunku przed publikacją.',
        'Tydzień jest ciężki od danych. Następne ważne: <b>{event}</b>, {eventWhen} o {eventTime}.<br><br><b class="al">Krok:</b> sprawdź w checkliście punkt „brak danych ★★★ w 15 min”.',
        'Przed danymi rynek to loteria, po danych to okazja. <b>{event}</b> wychodzi {eventWhen} o {eventTime}.<br><br><b class="al">Krok:</b> poczekaj na zamknięcie pierwszej świecy 15-minutowej po publikacji i dopiero wtedy szukaj setupu.',
        'Krótko: zmienność podwyższona, bo przed nami <b>{event}</b> ({eventWhen}, {eventTime}). Masz otwartą pozycję? Upewnij się, że SL jest ustawiony. Nie masz? Nie musisz się spieszyć.'
      ]
    }
  ];

  AV.PROACTIVE = [
    '{imie}, {straty} straty dziś i limit osiągnięty. Wiem, że korci. Zamknij platformę, a jutro wrócisz z planem.',
    'Stop na chwilę. {straty} z {limit} strat to sygnał, żeby odpuścić, a nie przyspieszyć. Tryb ochronny jest po to, żeby chronić Cię przed tym impulsem.',
    'Hej {imie}. Dziś już wystarczy. Najlepsze, co możesz teraz zrobić dla konta, to nic.',
    'Limit strat osiągnięty. Zawodowcy kończą dzień właśnie w takim momencie. Zapisz w dzienniku, co się pojawiło, i odpocznij.',
    '{imie}, po stracie pojawiło się już {poStracie} pozycji na minusie. Nie dawaj dziś tej liczbie kolejnego punktu.'
  ];

  /* ---------- kalendarz ---------- */
  AV.buildEvents = (base = new Date()) => {
    const monday = AV.monday(base);
    const defs = [
      // tydzień 0
      { w: 0, d: 0, h: 11, m: 0, cur: 'EUR', n: 'Indeks Sentix', desc: 'Nastroje inwestorów w strefie euro', imp: 2 },
      { w: 0, d: 1, h: 11, m: 0, cur: 'EUR', n: 'ZEW — nastroje w Niemczech', desc: 'Oczekiwania gospodarcze', imp: 2 },
      { w: 0, d: 2, h: 14, m: 30, cur: 'USD', n: 'Inflacja CPI w USA', desc: 'Najważniejsza publikacja tygodnia dla dolara', imp: 3 },
      { w: 0, d: 2, h: 16, m: 30, cur: 'USD', n: 'Zapasy ropy EIA', desc: 'Ropa i surowce', imp: 2 },
      { w: 0, d: 3, h: 14, m: 30, cur: 'USD', n: 'Wnioski o zasiłek dla bezrobotnych', desc: 'Cotygodniowy obraz rynku pracy', imp: 2 },
      { w: 0, d: 3, h: 16, m: 0, cur: 'USD', n: 'Nastroje konsumentów UoM', desc: 'Oczekiwania inflacyjne konsumentów', imp: 3 },
      { w: 0, d: 4, h: 14, m: 30, cur: 'USD', n: 'Inflacja PPI w USA', desc: 'Ceny producentów — zapowiedź CPI', imp: 2 },
      { w: 0, d: 4, h: 16, m: 0, cur: 'USD', n: 'Oczekiwania inflacyjne Michigan', desc: 'Długoterminowe oczekiwania cenowe', imp: 3 },
      // tydzień 1
      { w: 1, d: 0, h: 4, m: 0, cur: 'CNY', n: 'Produkcja przemysłowa w Chinach', desc: 'Dane z drugiej gospodarki świata', imp: 2 },
      { w: 1, d: 0, h: 14, m: 0, cur: 'EUR', n: 'Wystąpienie Christine Lagarde', desc: 'Prezes EBC o polityce pieniężnej', imp: 2 },
      { w: 1, d: 1, h: 14, m: 30, cur: 'USD', n: 'Sprzedaż detaliczna w USA', desc: 'Siła konsumenta — klucz dla indeksów', imp: 3 },
      { w: 1, d: 1, h: 15, m: 15, cur: 'USD', n: 'Produkcja przemysłowa w USA', desc: 'Kondycja przemysłu', imp: 2 },
      { w: 1, d: 2, h: 14, m: 30, cur: 'USD', n: 'Pozwolenia na budowę domów', desc: 'Rynek nieruchomości w USA', imp: 2 },
      { w: 1, d: 2, h: 20, m: 0, cur: 'USD', n: 'Protokół FOMC', desc: 'Szczegóły ostatniego posiedzenia Fed', imp: 3 },
      { w: 1, d: 3, h: 14, m: 30, cur: 'USD', n: 'Indeks Philly Fed', desc: 'Aktywność przemysłowa w regionie', imp: 2 },
      { w: 1, d: 3, h: 16, m: 0, cur: 'USD', n: 'Sprzedaż domów na rynku wtórnym', desc: 'Popyt na nieruchomości', imp: 2 },
      { w: 1, d: 4, h: 9, m: 30, cur: 'EUR', n: 'Wstępne PMI dla strefy euro', desc: 'Pierwszy odczyt aktywności gospodarczej', imp: 2 },
      { w: 1, d: 4, h: 14, m: 30, cur: 'CAD', n: 'Inflacja CPI w Kanadzie', desc: 'Dane przed posiedzeniem Bank of Canada', imp: 2 }
    ];
    return defs.map((x, i) => {
      const d = AV.addDays(monday, x.w * 7 + x.d);
      d.setHours(x.h, x.m, 0, 0);
      return {
        id: 'ev' + i,
        date: d.toISOString(),
        cur: x.cur,
        name: x.n,
        desc: x.desc,
        imp: x.imp
      };
    }).sort((a, b) => new Date(a.date) - new Date(b.date));
  };

  AV.eventStatus = (date, now = Date.now()) => {
    const d = new Date(date);
    const m = (d - now) / 60000;
    if (m < -60) return ['done', 'zakończone'];
    if (m <= 15 && m > -60) return ['live', 'teraz'];
    if (m < 120) return ['soon', 'za ' + Math.ceil(m) + ' min'];
    const dd = Math.round((AV.startOfDay(d) - AV.startOfDay(new Date())) / 864e5);
    if (dd === 0) return ['soon', 'dziś'];
    if (dd === 1) return ['next', 'jutro'];
    return ['next', 'za ' + dd + ' dni'];
  };

  /* ---------- generator demo ---------- */
  const mulberry32 = (a) => () => {
    a |= 0; a = a + 0x6D2B79F5 | 0;
    let t = Math.imul(a ^ a >>> 15, 1 | a);
    t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t;
    return ((t ^ t >>> 14) >>> 0) / 4294967296;
  };

  const gradePools = {
    A: [65, 48.2, 41.8, 72.4, 33.6, 28.9, 81.6, 39.7, 61.2, 44.8, 36.5, 52.3, 31.4, 47.6,
      -24.8, -31.2, -27.5, -35.4, -22.9, -28.6],
    B: [22.4, 18.6, 26.1, 14.8, 31.2, 19.4, 12.7, 24.9,
      -18.2, -22.6, -15.4, -24.1, -19.8, -16.3, -14.7],
    C: [12.4, 9.8, -49.5, -26, -18.2, -12.4, -9.8, -14.6, -11.2, -7.4, -13.9]
  };

  const pairBase = [
    ['BTC/USDT', 69120], ['ETH/USDT', 3410], ['SOL/USDT', 164.9], ['XRP/USDT', 0.598],
    ['DOGE/USDT', 0.179], ['BNB/USDT', 588], ['LINK/USDT', 14.2], ['AVAX/USDT', 27.4]
  ];

  const setupByGrade = {
    A: ['Retest wybicia', 'Odrzucenie oporu', 'Pullback w trendzie'],
    B: ['Range', 'Pullback w trendzie', 'Retest wybicia'],
    C: ['Wybicie bez retestu', 'Bez setupu', 'Range']
  };
  const emotionByGrade = {
    A: ['Spokój', 'Skupienie', 'Pewność'],
    B: ['Skupienie', 'Nuda', 'Pewność', 'Spokój'],
    C: ['FOMO', 'Złość', 'Nuda', 'Zmęczenie', 'Chciwość']
  };
  const errorsByGrade = {
    A: [[], [], ['Za wczesne wyjście']],
    B: [[], ['Wejście za wcześnie'], ['Przesunięty SL'], ['Za duża pozycja'], ['Za wczesne wyjście']],
    C: [['Brak setupu'], ['Gonienie ceny'], ['Bez SL'], ['Wejście po stracie'], ['Handel przy danych'], ['Za duża pozycja'], ['Brak setupu', 'Gonienie ceny'], ['Przesunięty SL']]
  };

  AV.buildDemo = (now = new Date()) => {
    const rnd = mulberry32(20261112);
    const pick = (a) => a[Math.floor(rnd() * a.length)];
    const today = AV.startOfDay(now);
    const trades = [];
    let seq = 0;

    const makeTrade = (opts) => {
      const grade = opts.grade;
      const result = opts.result;
      const base = opts.base;
      const dir = opts.dir;
      const entry = base * (1 + (rnd() - 0.5) * 0.004);
      const riskPct = opts.noSL ? 0 : (0.006 + rnd() * 0.006);
      const sl = opts.noSL ? null : entry * (1 - (dir === 'l' ? 1 : -1) * riskPct);
      const tp = entry * (1 + (dir === 'l' ? 1 : -1) * riskPct * (2.2 + rnd() * 1.6));
      const riskAmount = 26 + Math.floor(rnd() * 10);
      const r = result == null ? null : result / riskAmount;
      const qty = opts.noSL ? 0.01 : Math.max(0.0001, riskAmount / Math.abs(entry - sl));
      const close = result == null ? null : entry + (dir === 'l' ? 1 : -1) * (r || 0) * Math.abs(entry - sl);
      const d = new Date(opts.date);
      const dec = AV.dec(entry);
      const f = (v) => v == null ? 'brak' : AV.fmt(v, dec);
      const win = result != null && result > 0;
      const mae = result == null ? 0.3 : win ? 0.1 + rnd() * 0.55 : Math.abs(r) * (0.75 + rnd() * 0.5);
      const mfe = result == null ? 0.9 : win ? Math.max((r || 0) + 0.3, (r || 0) * (1.1 + rnd() * 0.8)) : rnd() * 0.8;
      const errors = opts.errors || [];
      const check = opts.check != null ? opts.check : grade === 'A' ? 7 : grade === 'B' ? 5 + Math.floor(rnd() * 2) : 2 + Math.floor(rnd() * 3);
      const emotion = opts.emotion || pick(emotionByGrade[grade]);
      const setup = opts.setup || pick(setupByGrade[grade]);
      const session = opts.session;
      const duration = opts.duration || (win ? 40 + Math.floor(rnd() * 95) : 110 + Math.floor(rnd() * 210));
      const fee = Math.round((0.4 + rnd() * 2.1) * 100) / 100;
      const note = result == null
        ? 'Retest 68 500 z planu dnia. SL pod knotem, TP na oporze. Pozycja otwarta.'
        : win
          ? 'Wejście zgodnie z planem, cierpliwe prowadzenie do TP. ' + (errors.includes('Za wczesne wyjście') ? 'Wyjście było wcześniejsze, niż zakładał plan.' : 'Bez pośpiechu, SL nietknięty.')
          : 'Wejście ' + (errors.includes('Bez SL') ? 'bez stop lossa. ' : '') + (errors.includes('Gonienie ceny') ? 'Cena była już daleko od poziomu. ' : '') + 'Pozycja zamknięta na minusie.';
      const mentor = result == null
        ? 'Wzorowo: plan, pełna checklista, SL, spokojna głowa. Tak wyglądają Twoje najlepsze transakcje.'
        : win
          ? (errors.includes('Za wczesne wyjście') ? 'Dobra transakcja, ale spójrz na MFE: cena poszła dalej niż planowane wyjście. Zostawiasz część zysku na stole.' : 'Podręcznikowy trade: plan, checklista, SL i cierpliwość. Powtarzaj dokładnie to.')
          : (errors.length ? 'Ta transakcja miała ' + errors.length + ' ' + AV.plural(errors.length, 'błąd', 'błędy', 'błędów') + ': ' + errors.join(', ') + '. To nie pech, to decyzja.' : 'Strata mieści się w planie. Sama strata nie jest błędem — błędem byłby brak SL albo za duża pozycja.');
      seq++;
      return {
        id: 'tr' + String(seq).padStart(3, '0') + '_' + d.getTime().toString(36),
        date: d.toISOString(),
        pair: opts.pair,
        dir,
        entry: f(entry),
        exit: result == null ? 'otwarta' : f(close),
        sl: f(sl),
        tp: f(tp),
        size: AV.fmt(qty, qty < 1 ? 4 : 2),
        result: result == null ? null : Math.round(result * 100) / 100,
        r: result == null ? null : Math.round(r * 100) / 100,
        grade,
        checklist: check,
        checkTotal: 7,
        errors,
        emotion,
        note,
        mentor,
        setup,
        session,
        mae: Math.round(mae * 100) / 100,
        mfe: Math.round(mfe * 100) / 100,
        durationMin: duration,
        fee,
        plan: opts.plan != null ? opts.plan : (grade !== 'C' && rnd() > 0.12),
        source: opts.source || 'WEEX',
        account: opts.account || 'WEEX',
        screens: {}
      };
    };

    const historical = [];
    Object.keys(gradePools).forEach((g) => gradePools[g].forEach((res) => {
      // dwie dzisiejsze straty dodajemy osobno, żeby nie zdublować ich w historii
      if (g === 'C' && (res === -49.5 || res === -26)) return;
      historical.push({ grade: g, result: res });
    }));

    // 44 transakcje historyczne: 12 ostatnich dni + reszta w 90 dniach
    const dates = [];
    for (let i = 1; i <= 12; i++) dates.push(AV.addDays(today, -i));
    for (let i = 0; i < 32; i++) {
      const offset = 13 + Math.floor((i / 32) * 76);
      const d = AV.addDays(today, -offset);
      d.setHours(9 + Math.floor(rnd() * 11), Math.floor(rnd() * 60), 0, 0);
      dates.push(d);
    }
    dates.sort((a, b) => a - b);

    // tasowanie wyników, ale ostatnie 12 dni dostaje równy miks
    for (let i = historical.length - 1; i > 0; i--) {
      const j = Math.floor(rnd() * (i + 1));
      [historical[i], historical[j]] = [historical[j], historical[i]];
    }

    dates.forEach((d, idx) => {
      const spec = historical[idx % historical.length];
      const pair = pairBase[Math.floor(rnd() * pairBase.length)];
      const dir = rnd() > 0.35 ? 'l' : 's';
      const hour = rnd() < 0.18 ? 2 + Math.floor(rnd() * 5) : rnd() < 0.55 ? 9 + Math.floor(rnd() * 3) : 14 + Math.floor(rnd() * 7);
      const session = hour < 8 ? 'Azja' : hour < 13 ? 'Londyn' : hour < 22 ? 'Nowy Jork' : 'Po godzinach';
      d.setHours(hour, Math.floor(rnd() * 60), 0, 0);
      const grade = spec.grade;
      const noSL = grade === 'C' && spec.result < -20 && idx % 9 === 0;
      const errors = AV.clone(pick(errorsByGrade[grade]));
      if (noSL && !errors.includes('Bez SL')) errors.push('Bez SL');
      if (hour >= 21 && grade !== 'A') {
        if (!errors.includes('Handel przy danych') && rnd() > 0.6) errors.push('Handel przy danych');
      }
      trades.push(makeTrade({
        date: d, pair: pair[0], base: pair[1], dir, grade, result: spec.result,
        errors, session, noSL, plan: grade !== 'C' ? rnd() > 0.1 : rnd() > 0.75
      }));
    });

    // dzisiejsze transakcje — dokładnie 2 straty i 1 otwarta
    const todayLoss1 = makeTrade({
      date: AV.dailyKeyFrom(today, '10:42'), pair: 'SOL/USDT', base: 164.9, dir: 'l', grade: 'C',
      result: -49.5, errors: ['Wejście po stracie', 'Za duża pozycja', 'Brak setupu'],
      emotion: 'Złość', setup: 'Bez setupu', session: 'Nowy Jork', check: 3,
      note: 'Wejście 12 min po stracie na ETH. Pozycja 2,3× większa niż zwykle.',
      plan: false
    });
    const todayLoss2 = makeTrade({
      date: AV.dailyKeyFrom(today, '10:18'), pair: 'ETH/USDT', base: 3410, dir: 's', grade: 'C',
      result: -26, errors: ['Bez SL', 'Gonienie ceny'], emotion: 'FOMO', setup: 'Wybicie bez retestu',
      session: 'Nowy Jork', check: 3, noSL: true, plan: false,
      note: 'Gonienie ruchu po dużej czerwonej świecy. Wejście bez stop lossa.'
    });
    const openTrade = makeTrade({
      date: AV.dailyKeyFrom(today, '08:55'), pair: 'BTC/USDT', base: 69120, dir: 'l', grade: 'A',
      result: null, errors: [], emotion: 'Spokój', setup: 'Retest wybicia', session: 'Londyn', check: 7,
      plan: true, note: 'Retest 68 500 z planu dnia. SL pod knotem, TP na oporze 70 800.'
    });
    trades.push(todayLoss2, todayLoss1, openTrade);
    trades.sort((a, b) => new Date(a.date) - new Date(b.date));

    // plany z ostatnich 10 dni
    const plans = {};
    const planFor = (date, approved, shift) => {
      const k = AV.dateKey(date);
      plans[k] = {
        date: k,
        market: ['BTC', 'ETH', 'BTC', 'SOL'][shift % 4],
        bias: shift % 5 === 4 ? 'neutral' : 'long',
        why: 'D1 w trendzie wzrostowym, wybicie poprzedniego szczytu i zamknięcie nad poziomem. Czekam na retest.',
        levels: [
          { type: 'Opór', price: '71 200', note: 'szczyt z 28.10' },
          { type: 'Wsparcie', price: '68 500', note: 'wybicie, strefa retestu' },
          { type: 'Wsparcie', price: '67 800', note: 'unieważnienie biasu' }
        ],
        ifMain: 'retest 68 500 i zamknięcie M15 nad',
        thenMain: 'long, SL 68 090, TP 71 200',
        ifAlt: 'zamknięcie H1 pod 67 800',
        thenAlt: 'nie gram longów, czekam do jutra',
        risk: '3%', maxTrades: '5', until: '21:00',
        notDo: 'Nie wchodzę w SOL. Nie gram 15 min przed i po danych.',
        approvedAt: approved ? new Date(date).setHours(8, 12, 0, 0) : null
      };
    };
    planFor(today, true, 0);
    for (let i = 1; i <= 10; i++) planFor(AV.addDays(today, -i), i !== 3 && i !== 8, i);

    // check-iny
    const checkins = [];
    for (let i = 14; i >= 1; i--) {
      const d = AV.addDays(today, -i);
      const moods = ['Spokój', 'Skupienie', 'Zmęczenie', 'Nuda', 'FOMO'];
      checkins.push({
        date: AV.dateKey(d),
        mood: moods[i % moods.length],
        energy: 2 + (i % 4),
        note: '',
        score: 45 + ((i * 7) % 40)
      });
    }
    checkins.push({ date: AV.dateKey(today), mood: 'Zmęczenie', energy: 2, note: 'Wczorajsza strata, chcę ją odrobić…', score: 54 });

    return {
      version: 2,
      user: {
        name: 'Kasia', email: 'kasia@example.com', joined: '2026-03-01',
        avatar: null, markets: ['Krypto'],
        goal: '+6% bez łamania zasad',
        currency: 'USDT', timezone: 'Polska (Warszawa)', hour12: false,
        accent: { a: '217,178,95', b: '#f1d48e' }
      },
      subscription: { plan: 'full', until: '2026-12-03', expired: false, role: 'Alpha', renewedAt: null },
      settings: {
        remember: true, apiCode: true, safetyEnabled: true, requireReason: true, pushLimit: true,
        macroBefore: true, macroChecklist: true, macroTwo: false,
        remindDay: true, remindHour: true,
        maxLosses: 2, maxDailyLoss: 3, block: 'midnight',
        maxRisk: 1, rules: ['Max 1% ryzyka na transakcję', 'Zawsze stop loss', 'Po 2 stratach koniec dnia'],
        checklist: ['Trend wyższego TF', 'Poziom z planu', 'Potwierdzenie', 'SL przed wejściem', 'R:R ≥ 2', 'Brak makro 15 min', 'Spokojna głowa'],
        devices: { garmin: false, apple: false, oura: false, whoop: false },
        connections: {
          weex: { connected: true, lastSync: new Date().toISOString(), imported: 41, masked: 'wx_live_••••7f2a', perms: 'read-only' },
          okx: { connected: false }, bingx: { connected: false },
          mt: { connected: false, server: '', login: '', masked: '', code: 'AV-7K3Q-92XD', codeAt: Date.now() }
        }
      },
      health: null,
      plans,
      trades,
      setups: [
        { id: 'pb1', name: 'Retest wybicia', grade: 'A', tf: 'M15 · H1', rules: ['Wybicie poziomu z planu i zamknięcie świecy H1 nad nim', 'Wejście na retest, potwierdzone zamknięciem M15', 'SL pod knotem retestu, TP na następnym oporze'], seed: 17, bad: false },
        { id: 'pb2', name: 'Odrzucenie oporu', grade: 'A', tf: 'H1', rules: ['Cena testuje opór z planu dnia', 'Świeca odrzucenia z długim knotem', 'Short po zamknięciu, SL nad knotem'], seed: 48, bad: false },
        { id: 'pb3', name: 'Range', grade: 'B', tf: 'M15', rules: ['Wyraźne granice zakresu na H1', 'Wejście tylko przy krawędzi zakresu', 'Wyjście na środku albo przy drugiej krawędzi'], seed: 79, bad: false },
        { id: 'pb4', name: 'Pullback w trendzie', grade: 'B', tf: 'H4 · H1', rules: ['Trend D1 i H4 w tę samą stronę', 'Cofnięcie do średniej albo poprzedniego poziomu', 'SL za ostatnim dołkiem lub szczytem'], seed: 110, bad: false },
        { id: 'pb5', name: 'Wybicie bez retestu', grade: 'C', tf: 'M5 · M15', rules: ['Wejście w trakcie dużej świecy', 'Często z FOMO', 'Statystycznie zabiera Ci pieniądze'], seed: 141, bad: true }
      ],
      accounts: [
        { id: 'acc1', name: 'WEEX', exchange: 'WEEX', type: 'Futures', currency: 'USDT', start: 1500, date: '01.03.2026', lev: '10×', goal: '+5% / mies.', balance: 1812.4, include: true, primary: true },
        { id: 'acc2', name: 'OKX', exchange: 'OKX', type: 'Spot · długi termin', currency: 'USDT', start: 500, date: '15.04.2026', lev: '1×', goal: '+3% / mies.', balance: 600.4, include: true, primary: false },
        { id: 'acc3', name: 'MT5 · demo', exchange: 'MetaTrader 5', type: 'Forex · ćwiczenia', currency: 'USD', start: 10000, date: '01.10.2026', lev: '30×', goal: 'trening', balance: 9640, include: false, primary: false }
      ],
      flows: [
        { id: 'f1', date: '2026-03-12', account: 'WEEX', type: 'Wpłata', op: 'zakup USDT za PLN (P2P)', amount: 508.4, currency: 'PLN', fiat: 2000, fx: 1, tax: 'cost' },
        { id: 'f2', date: '2026-06-02', account: 'WEEX', type: 'Wpłata', op: 'zakup USDT za PLN (P2P)', amount: 791.6, currency: 'PLN', fiat: 2980, fx: 1, tax: 'cost' },
        { id: 'f3', date: '2026-08-18', account: 'OKX', type: 'Wypłata', op: 'sprzedaż USDT za EUR', amount: 560, currency: 'EUR', fiat: 520, fx: 4.265, tax: 'income' },
        { id: 'f4', date: '2026-10-30', account: 'WEEX', type: 'Wypłata', op: 'sprzedaż USDT za PLN (P2P)', amount: 1402.1, currency: 'PLN', fiat: 5202.94, fx: 1, tax: 'income' }
      ],
      tax: { carryLoss: 380, year: 2026 },
      checkins,
      chat: [],
      overrides: [],
      meta: {
        streakBase: 12,
        notified: {},
        liveReminder: false,
        consultation: { date: '2026-11-17', time: '18:00', who: 'Marcin' },
        consultationUsed: 1,
        courseProgress: 64,
        sessions: [
          { device: 'MacBook · Chrome', place: 'Warszawa', when: 'teraz' },
          { device: 'iPhone · Alpha Vault', place: '', when: 'wczoraj 21:14' }
        ],
        createdAt: Date.now()
      }
    };
  };
})();
