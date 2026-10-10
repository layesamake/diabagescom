<?php

/*
 * Messages de validation en français clair (docs/07-ux-ui.md) : ils indiquent
 * la correction attendue. Les noms de champs viennent de "attributes" ou des
 * FormRequest::attributes().
 */
return [
    'accepted' => 'Vous devez accepter :attribute.',
    'active_url' => ':Attribute n’est pas une adresse web valide.',
    'after' => ':Attribute doit être une date postérieure au :date.',
    'after_or_equal' => ':Attribute doit être une date égale ou postérieure au :date.',
    'alpha' => ':Attribute ne doit contenir que des lettres.',
    'alpha_dash' => ':Attribute ne doit contenir que des lettres, chiffres, tirets et tirets bas.',
    'alpha_num' => ':Attribute ne doit contenir que des lettres et des chiffres.',
    'array' => ':Attribute doit être une liste.',
    'before' => ':Attribute doit être une date antérieure au :date.',
    'before_or_equal' => ':Attribute doit être une date égale ou antérieure au :date.',
    'between' => [
        'array' => ':Attribute doit contenir entre :min et :max éléments.',
        'file' => ':Attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => ':Attribute doit être compris entre :min et :max.',
        'string' => ':Attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => ':Attribute doit être vrai ou faux.',
    'confirmed' => 'La confirmation de :attribute ne correspond pas.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => ':Attribute n’est pas une date valide.',
    'date_format' => ':Attribute doit respecter le format :format.',
    'different' => ':Attribute et :other doivent être différents.',
    'digits' => ':Attribute doit contenir :digits chiffres.',
    'digits_between' => ':Attribute doit contenir entre :min et :max chiffres.',
    'email' => ':Attribute doit être une adresse e-mail valide.',
    'exists' => ':Attribute sélectionné est invalide.',
    'file' => ':Attribute doit être un fichier.',
    'filled' => ':Attribute doit être renseigné.',
    'gt' => [
        'numeric' => ':Attribute doit être supérieur à :value.',
        'string' => ':Attribute doit contenir plus de :value caractères.',
    ],
    'gte' => [
        'numeric' => ':Attribute doit être supérieur ou égal à :value.',
        'string' => ':Attribute doit contenir au moins :value caractères.',
    ],
    'image' => ':Attribute doit être une image.',
    'in' => ':Attribute sélectionné est invalide.',
    'integer' => ':Attribute doit être un nombre entier.',
    'ip' => ':Attribute doit être une adresse IP valide.',
    'json' => ':Attribute doit être un document JSON valide.',
    'lowercase' => ':Attribute doit être en minuscules.',
    'lt' => [
        'numeric' => ':Attribute doit être inférieur à :value.',
        'string' => ':Attribute doit contenir moins de :value caractères.',
    ],
    'lte' => [
        'numeric' => ':Attribute doit être inférieur ou égal à :value.',
        'string' => ':Attribute doit contenir au plus :value caractères.',
    ],
    'max' => [
        'array' => ':Attribute ne doit pas contenir plus de :max éléments.',
        'file' => ':Attribute ne doit pas dépasser :max kilo-octets.',
        'numeric' => ':Attribute ne doit pas dépasser :max.',
        'string' => ':Attribute ne doit pas dépasser :max caractères.',
    ],
    'mimes' => ':Attribute doit être un fichier de type : :values.',
    'mimetypes' => ':Attribute doit être un fichier de type : :values.',
    'min' => [
        'array' => ':Attribute doit contenir au moins :min éléments.',
        'file' => ':Attribute doit peser au moins :min kilo-octets.',
        'numeric' => ':Attribute doit être au moins égal à :min.',
        'string' => ':Attribute doit contenir au moins :min caractères.',
    ],
    'not_in' => ':Attribute sélectionné est invalide.',
    'numeric' => ':Attribute doit être un nombre.',
    'password' => [
        'letters' => ':Attribute doit contenir au moins une lettre.',
        'mixed' => ':Attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => ':Attribute doit contenir au moins un chiffre.',
        'symbols' => ':Attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Ce :attribute est apparu dans une fuite de données. Choisissez-en un autre.',
    ],
    'present' => ':Attribute doit être présent.',
    'regex' => 'Le format de :attribute est invalide.',
    'required' => ':Attribute est obligatoire.',
    'required_if' => ':Attribute est obligatoire quand :other vaut :value.',
    'required_with' => ':Attribute est obligatoire quand :values est renseigné.',
    'same' => ':Attribute et :other doivent être identiques.',
    'size' => [
        'array' => ':Attribute doit contenir :size éléments.',
        'file' => ':Attribute doit peser :size kilo-octets.',
        'numeric' => ':Attribute doit valoir :size.',
        'string' => ':Attribute doit contenir :size caractères.',
    ],
    'string' => ':Attribute doit être un texte.',
    'timezone' => ':Attribute doit être un fuseau horaire valide.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => 'Le téléversement de :attribute a échoué.',
    'url' => ':Attribute doit être une adresse web valide.',
    'uuid' => ':Attribute doit être un identifiant valide.',

    'custom' => [
        'warehouse_code' => [
            'regex' => 'Le code du dépôt ne doit contenir que des majuscules, chiffres, tirets ou tirets bas, sans espace (ex. DKR-1).',
        ],
    ],

    'attributes' => [
        'name' => 'le nom',
        'email' => 'l’adresse e-mail',
        'password' => 'le mot de passe',
        'password_confirmation' => 'la confirmation du mot de passe',
        'current_password' => 'le mot de passe actuel',
        'code' => 'le code',
        'recovery_code' => 'le code de secours',
    ],
];
