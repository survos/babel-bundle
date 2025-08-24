<?php

namespace Survos\BabelBundle\Service;

use Symfony\Contracts\Translation\TranslatorInterface;

class FakeTranslatorService implements TranslatorInterface
{
    private array $spanishCommonWords = [
        // Pronouns
        'i' => 'yo',
        'you' => 'tú',
        'he' => 'él',
        'she' => 'ella',
        'we' => 'nosotros',
        'they' => 'ellos',
        'me' => 'me',
        'my' => 'mi',
        'your' => 'tu',
        'his' => 'su',
        'her' => 'su',
        'our' => 'nuestro',
        'their' => 'su',

        // Common verbs (infinitive forms)
        'is' => 'es',
        'are' => 'son',
        'am' => 'soy',
        'have' => 'tener',
        'has' => 'tiene',
        'do' => 'hacer',
        'does' => 'hace',
        'go' => 'ir',
        'goes' => 'va',
        'come' => 'venir',
        'comes' => 'viene',
        'want' => 'querer',
        'wants' => 'quiere',
        'like' => 'gustar',
        'likes' => 'gusta',
        'see' => 'ver',
        'sees' => 've',
        'know' => 'saber',
        'knows' => 'sabe',
        'think' => 'pensar',
        'thinks' => 'piensa',
        'say' => 'decir',
        'says' => 'dice',
        'get' => 'obtener',
        'gets' => 'obtiene',
        'make' => 'hacer',
        'makes' => 'hace',
        'take' => 'tomar',
        'takes' => 'toma',
        'give' => 'dar',
        'gives' => 'da',
        'find' => 'encontrar',
        'finds' => 'encuentra',
        'feel' => 'sentir',
        'feels' => 'siente',
        'become' => 'convertirse',
        'becomes' => 'se convierte',
        'leave' => 'salir',
        'leaves' => 'sale',
        'put' => 'poner',
        'puts' => 'pone',
        'call' => 'llamar',
        'calls' => 'llama',
        'try' => 'tratar',
        'tries' => 'trata',
        'ask' => 'preguntar',
        'asks' => 'pregunta',
        'work' => 'trabajar',
        'works' => 'trabaja',
        'seem' => 'parecer',
        'seems' => 'parece',
        'help' => 'ayudar',
        'helps' => 'ayuda',
        'play' => 'jugar',
        'plays' => 'juega',
        'move' => 'mover',
        'moves' => 'mueve',
        'live' => 'vivir',
        'lives' => 'vive',
        'believe' => 'creer',
        'believes' => 'cree',
        'bring' => 'traer',
        'brings' => 'trae',
        'happen' => 'pasar',
        'happens' => 'pasa',
        'write' => 'escribir',
        'writes' => 'escribe',
        'sit' => 'sentar',
        'sits' => 'se sienta',
        'stand' => 'estar de pie',
        'stands' => 'está de pie',
        'lose' => 'perder',
        'loses' => 'pierde',
        'pay' => 'pagar',
        'pays' => 'paga',
        'meet' => 'conocer',
        'meets' => 'conoce',
        'run' => 'correr',
        'runs' => 'corre',

        // Prepositions and conjunctions
        'and' => 'y',
        'or' => 'o',
        'but' => 'pero',
        'in' => 'en',
        'on' => 'en',
        'at' => 'en',
        'to' => 'a',
        'for' => 'para',
        'of' => 'de',
        'with' => 'con',
        'by' => 'por',
        'from' => 'de',
        'about' => 'sobre',
        'into' => 'en',
        'through' => 'a través',
        'during' => 'durante',
        'before' => 'antes',
        'after' => 'después',
        'above' => 'arriba',
        'below' => 'abajo',
        'between' => 'entre',
        'among' => 'entre',
        'under' => 'bajo',
        'over' => 'sobre',

        // Common adjectives
        'good' => 'bueno',
        'bad' => 'malo',
        'big' => 'grande',
        'small' => 'pequeño',
        'long' => 'largo',
        'short' => 'corto',
        'high' => 'alto',
        'low' => 'bajo',
        'hot' => 'caliente',
        'cold' => 'frío',
        'new' => 'nuevo',
        'old' => 'viejo',
        'young' => 'joven',
        'happy' => 'feliz',
        'sad' => 'triste',
        'easy' => 'fácil',
        'hard' => 'difícil',
        'fast' => 'rápido',
        'slow' => 'lento',
        'right' => 'correcto',
        'wrong' => 'incorrecto',
        'different' => 'diferente',
        'same' => 'mismo',
        'important' => 'importante',
        'free' => 'gratis',
        'full' => 'lleno',
        'empty' => 'vacío',
        'strong' => 'fuerte',
        'weak' => 'débil',
        'clean' => 'limpio',
        'dirty' => 'sucio',
        'beautiful' => 'hermoso',
        'ugly' => 'feo',
        'rich' => 'rico',
        'poor' => 'pobre',

        // Time and numbers
        'today' => 'hoy',
        'tomorrow' => 'mañana',
        'yesterday' => 'ayer',
        'now' => 'ahora',
        'then' => 'entonces',
        'here' => 'aquí',
        'there' => 'allí',
        'where' => 'dónde',
        'when' => 'cuándo',
        'how' => 'cómo',
        'what' => 'qué',
        'why' => 'por qué',
        'who' => 'quién',
        'which' => 'cuál',
        'one' => 'uno',
        'two' => 'dos',
        'three' => 'tres',
        'four' => 'cuatro',
        'five' => 'cinco',
        'six' => 'seis',
        'seven' => 'siete',
        'eight' => 'ocho',
        'nine' => 'nueve',
        'ten' => 'diez',
        'first' => 'primero',
        'last' => 'último',
        'next' => 'siguiente',

        // Common nouns (these will be overridden by our noun logic)
        'time' => 'tiempo',
        'person' => 'persona',
        'people' => 'gente',
        'way' => 'manera',
        'day' => 'día',
        'man' => 'hombre',
        'woman' => 'mujer',
        'child' => 'niño',
        'world' => 'mundo',
        'life' => 'vida',
        'hand' => 'mano',
        'part' => 'parte',
        'place' => 'lugar',
        'work' => 'trabajo',
        'week' => 'semana',
        'case' => 'caso',
        'point' => 'punto',
        'government' => 'gobierno',
        'company' => 'empresa',
    ];

    private array $feminineMasculinePatterns = [
        // Feminine endings typically get 'la'
        '/(?:tion|sion|dad|tad|tud)$/' => 'la',
        // Masculine endings typically get 'el'
        '/(?:ment|ing|er|or|ism|age)$/' => 'el',
        // Words ending in 'a' often feminine
        '/a$/' => 'la',
        // Words ending in 'o' often masculine
        '/o$/' => 'el',
        // Words ending in consonants often masculine
        '/[bcdfghjklmnpqrstvwxyz]$/' => 'el',
    ];

    private array $spanishAccents = ['á', 'é', 'í', 'ó', 'ú', 'ñ'];

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translateToSpanish($id, $parameters);
    }

    public function setLocale(string $locale): void
    {
        // This fake translator only does Spanish for now
    }

    public function getLocale(): string
    {
        return 'es';
    }

    private function translateToSpanish(string $text, array $parameters = []): string
    {
        // Handle parameters first
        foreach ($parameters as $key => $value) {
            $text = str_replace('%' . $key . '%', (string) $value, $text);
        }

        // Split into words while preserving punctuation and spacing
        $tokens = preg_split('/(\s+|[.,!?;:()"\'-])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            $lowerToken = strtolower(trim($token));

            // Skip empty tokens, whitespace, and punctuation
            if (empty($lowerToken) || preg_match('/^\s*$/', $token) || preg_match('/^[.,!?;:()"\'-]+$/', $token)) {
                $result[] = $token;
                continue;
            }

            // Handle "the" specially - need to look ahead for gender
            if ($lowerToken === 'the') {
                $nextWord = $this->getNextWord($tokens, $i);
                $result[] = $this->getSpanishArticle($nextWord);
                continue;
            }

            // Check if it's a common word we have a direct translation for
            if (isset($this->spanishCommonWords[$lowerToken])) {
                $result[] = $this->preserveCase($this->spanishCommonWords[$lowerToken], $token);
                continue;
            }

            // Apply hacky Spanish transformations
            $transformed = $this->hackifyToSpanish($token);
            $result[] = $transformed;
        }

        return implode('', $result);
    }

    private function getNextWord(array $tokens, int $currentIndex): string
    {
        for ($i = $currentIndex + 1; $i < count($tokens); $i++) {
            $token = trim($tokens[$i]);
            if (!empty($token) && !preg_match('/^\s*$/', $token) && !preg_match('/^[.,!?;:()"\'-]+$/', $token)) {
                return strtolower($token);
            }
        }
        return '';
    }

    private function getSpanishArticle(string $nextWord): string
    {
        if (empty($nextWord)) {
            return 'el'; // Default to masculine
        }

        // Check if the next word is plural
        if (preg_match('/s$/', $nextWord)) {
            // Determine if plural should be masculine or feminine
            $singular = preg_replace('/s$/', '', $nextWord);
            foreach ($this->feminineMasculinePatterns as $pattern => $article) {
                if (preg_match($pattern, $singular)) {
                    return $article === 'la' ? 'las' : 'los';
                }
            }
            return 'los'; // Default plural masculine
        }

        // Singular - check patterns
        foreach ($this->feminineMasculinePatterns as $pattern => $article) {
            if (preg_match($pattern, $nextWord)) {
                return $article;
            }
        }

        return 'el'; // Default to masculine
    }

    private function hackifyToSpanish(string $word): string
    {
        $lowerWord = strtolower($word);
        $result = $word;

        // Handle verbs ending in common English patterns
        if (preg_match('/ing$/', $lowerWord)) {
            // Convert "-ing" to "-ando" or "-iendo"
            $base = preg_replace('/ing$/', '', $lowerWord);
            if (preg_match('/[aeiou]$/', $base)) {
                $result = $base . 'ndo';
            } else {
                $result = $base . 'ando';
            }
        } elseif (preg_match('/ed$/', $lowerWord)) {
            // Convert "-ed" to "-ado" or "-ido"
            $base = preg_replace('/ed$/', '', $lowerWord);
            $result = $base . 'ado';
        } elseif (preg_match('/er$/', $lowerWord)) {
            // Convert "-er" to "-ero" (like worker -> trabajero)
            $result = $lowerWord . 'o';
        } elseif (preg_match('/ly$/', $lowerWord)) {
            // Convert "-ly" to "-mente"
            $base = preg_replace('/ly$/', '', $lowerWord);
            $result = $base . 'mente';
        } elseif (preg_match('/tion$/', $lowerWord)) {
            // Convert "-tion" to "-ción"
            $base = preg_replace('/tion$/', '', $lowerWord);
            $result = $base . 'ción';
        } elseif (preg_match('/sion$/', $lowerWord)) {
            // Convert "-sion" to "-sión"
            $base = preg_replace('/sion$/', '', $lowerWord);
            $result = $base . 'sión';
        } else {
            // For nouns, add Spanish-like endings
            if (preg_match('/[bcdfghjklmnpqrstvwxyz]$/', $lowerWord)) {
                // Consonant ending - make it masculine or add vowel
                if (strlen($lowerWord) > 4 && rand(0, 1)) {
                    $result = $lowerWord . 'o';
                } else {
                    $result = $lowerWord . 'a';
                }
            } elseif (preg_match('/e$/', $lowerWord)) {
                // Replace 'e' with 'a' or 'o'
                $result = preg_replace('/e$/', rand(0, 1) ? 'a' : 'o', $lowerWord);
            }
            // Words already ending in a/o are probably fine
        }

        // Add some Spanish accents randomly to make it look more Spanish
        $result = $this->addRandomSpanishAccents($result);

        // Preserve original case
        $result = $this->preserveCase($result, $word);

        return $result;
    }

    private function addRandomSpanishAccents(string $word): string
    {
        // Only add accents occasionally and to longer words
        if (strlen($word) < 4 || rand(1, 4) > 1) {
            return $word;
        }

        $vowels = ['a', 'e', 'i', 'o', 'u'];
        $accented = ['á', 'é', 'í', 'ó', 'ú'];

        // Replace a random vowel with an accented version
        for ($i = 0; $i < count($vowels); $i++) {
            if (strpos($word, $vowels[$i]) !== false && rand(1, 3) === 1) {
                $word = preg_replace('/' . $vowels[$i] . '/', $accented[$i], $word, 1);
                break;
            }
        }

        // Sometimes add ñ if there's an 'n'
        if (strpos($word, 'n') !== false && rand(1, 8) === 1) {
            $word = preg_replace('/n/', 'ñ', $word, 1);
        }

        return $word;
    }

    private function preserveCase(string $newWord, string $originalWord): string
    {
        if (ctype_upper($originalWord)) {
            return strtoupper($newWord);
        } elseif (ctype_upper($originalWord[0] ?? '')) {
            return ucfirst(strtolower($newWord));
        } else {
            return strtolower($newWord);
        }
    }

    public function getCatalogue(?string $locale = null): \Symfony\Component\Translation\MessageCatalogueInterface
    {
        throw new \RuntimeException('This fake translator does not support catalogues');
    }
}
