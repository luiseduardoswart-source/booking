<?php

/*
 * The messages the website can show. If a rule is missing here, Laravel
 * falls back to lang/nl/validation.php.
 */
return [

    'accepted' => ':attribute muss akzeptiert werden.',
    'after' => ':attribute muss ein Datum nach dem :date sein.',
    'after_or_equal' => ':attribute muss ein Datum am oder nach dem :date sein.',
    'array' => ':attribute muss eine Liste sein.',
    'before' => ':attribute muss ein Datum vor dem :date sein.',
    'before_or_equal' => ':attribute muss ein Datum am oder vor dem :date sein.',
    'boolean' => ':attribute muss ja oder nein sein.',
    'confirmed' => 'Die Bestätigung von :attribute stimmt nicht überein.',
    'date' => ':attribute ist kein gültiges Datum.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'exists' => 'Der gewählte Wert für :attribute ist ungültig.',
    'file' => ':attribute muss eine Datei sein.',
    'image' => ':attribute muss ein Bild sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'max' => [
        'array' => ':attribute darf höchstens :max Elemente haben.',
        'file' => ':attribute darf höchstens :max Kilobyte groß sein.',
        'numeric' => ':attribute darf höchstens :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen haben.',
    ],
    'mimes' => ':attribute muss eine Datei vom Typ :values sein.',
    'min' => [
        'array' => ':attribute muss mindestens :min Elemente haben.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen haben.',
    ],
    'numeric' => ':attribute muss eine Zahl sein.',
    'regex' => 'Das Format von :attribute ist ungültig.',
    'required' => ':attribute ist ein Pflichtfeld.',
    'required_if' => ':attribute ist erforderlich, wenn :other :value ist.',
    'required_with' => ':attribute ist erforderlich, wenn :values angegeben ist.',
    'same' => ':attribute und :other müssen übereinstimmen.',
    'string' => ':attribute muss ein Text sein.',
    'unique' => 'Dieser Wert für :attribute wird bereits verwendet.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'url' => ':attribute muss eine gültige URL sein.',

    'attributes' => [
        'address' => 'Adresse',
        'adults' => 'Anzahl der Erwachsenen',
        'arrival' => 'Anreise',
        'children' => 'Anzahl der Kinder',
        'city' => 'Ort',
        'comment' => 'Kommentar',
        'coupon' => 'Rabattcode',
        'departure' => 'Abreise',
        'email' => 'E-Mail-Adresse',
        'guests' => 'Anzahl der Gäste',
        'signature' => 'Unterschrift',
        'infants' => 'Anzahl der Babys',
        'message' => 'Nachricht',
        'name' => 'Name',
        'notes' => 'Anmerkungen',
        'password' => 'Passwort',
        'pets' => 'Anzahl der Haustiere',
        'phone' => 'Telefon',
        'photo' => 'Foto',
        'postal_code' => 'Postleitzahl',
        'rating' => 'Bewertung',
        'terms' => 'Bedingungen',
    ],

];
