<?php
/**
 * Клас для семантичного порівняння текстів
 */

if (!defined('ABSPATH')) {
    exit;
}

class SPR_Semantic_Matcher {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Обчислення семантичної схожості між двома текстами
     */
    public function calculate_similarity($text1, $text2) {
        if (empty($text1) || empty($text2)) {
            return 0;
        }
        
        // Нормалізація текстів
        $text1 = $this->normalize_text($text1);
        $text2 = $this->normalize_text($text2);
        
        // Розбиття на слова
        $words1 = $this->tokenize($text1);
        $words2 = $this->tokenize($text2);
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        // Комбінований підхід
        $exact_score = $this->exact_match_score($words1, $words2);
        $partial_score = $this->partial_match_score($words1, $words2);
        $levenshtein_score = $this->levenshtein_score($words1, $words2);
        
        // Зважена комбінація
        $final_score = ($exact_score * 0.5) + ($partial_score * 0.3) + ($levenshtein_score * 0.2);
        
        return min($final_score, 1.0);
    }
    
    /**
     * Нормалізація тексту
     */
    private function normalize_text($text) {
        // Видалення HTML тегів
        $text = wp_strip_all_tags($text);
        
        // Перетворення в нижній регістр
        $text = mb_strtolower($text, 'UTF-8');
        
        // Видалення спеціальних символів (залишаємо букви, цифри, пробіли)
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);
        
        // Заміна множинних пробілів на один
        $text = preg_replace('/\s+/', ' ', $text);
        
        return trim($text);
    }
    
    /**
     * Токенізація тексту
     */
    private function tokenize($text) {
        $words = explode(' ', $text);
        
        // Видалення стоп-слів (для української мови)
        $stopwords = $this->get_stopwords();
        $words = array_filter($words, function($word) use ($stopwords) {
            return !in_array($word, $stopwords) && mb_strlen($word, 'UTF-8') > 2;
        });
        
        return array_values($words);
    }
    
    /**
     * Стоп-слова для української мови
     */
    private function get_stopwords() {
        return array(
            'і', 'в', 'на', 'з', 'до', 'для', 'та', 'або', 'але', 'при', 'про',
            'від', 'по', 'як', 'це', 'що', 'чи', 'ми', 'ви', 'він', 'вона',
            'воно', 'вони', 'їх', 'всі', 'коли', 'який', 'яка', 'яке', 'які',
            'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
            'of', 'with', 'by', 'from', 'is', 'are', 'was', 'were', 'be', 'been'
        );
    }
    
    /**
     * Точне співпадіння слів
     */
    private function exact_match_score($words1, $words2) {
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        
        if (count($union) === 0) {
            return 0;
        }
        
        // Коефіцієнт Жаккара
        return count($intersection) / count($union);
    }
    
    /**
     * Часткове співпадіння слів (підрядки)
     */
    private function partial_match_score($words1, $words2) {
        $matches = 0;
        $total = count($words1) * count($words2);
        
        if ($total === 0) {
            return 0;
        }
        
        foreach ($words1 as $word1) {
            foreach ($words2 as $word2) {
                // Перевірка чи одне слово міститься в іншому
                if (mb_strpos($word1, $word2, 0, 'UTF-8') !== false || 
                    mb_strpos($word2, $word1, 0, 'UTF-8') !== false) {
                    $matches++;
                }
            }
        }
        
        return $matches / sqrt($total);
    }
    
    /**
     * Відстань Левенштейна для порівняння слів
     */
    private function levenshtein_score($words1, $words2) {
        $total_similarity = 0;
        $comparisons = 0;
        
        foreach ($words1 as $word1) {
            $max_similarity = 0;
            
            foreach ($words2 as $word2) {
                $similarity = $this->word_similarity($word1, $word2);
                if ($similarity > $max_similarity) {
                    $max_similarity = $similarity;
                }
            }
            
            $total_similarity += $max_similarity;
            $comparisons++;
        }
        
        return $comparisons > 0 ? $total_similarity / $comparisons : 0;
    }
    
    /**
     * Схожість між двома словами
     */
    private function word_similarity($word1, $word2) {
        $len1 = mb_strlen($word1, 'UTF-8');
        $len2 = mb_strlen($word2, 'UTF-8');
        
        if ($len1 === 0 || $len2 === 0) {
            return 0;
        }
        
        $max_len = max($len1, $len2);
        
        // Для коротких слів використовуємо стандартний levenshtein
        if ($max_len <= 255) {
            $distance = levenshtein($word1, $word2);
            return 1 - ($distance / $max_len);
        }
        
        // Для довгих слів використовуємо схожість за першими літерами
        $prefix_len = min($len1, $len2, 10);
        $prefix1 = mb_substr($word1, 0, $prefix_len, 'UTF-8');
        $prefix2 = mb_substr($word2, 0, $prefix_len, 'UTF-8');
        
        $distance = levenshtein($prefix1, $prefix2);
        return 1 - ($distance / $prefix_len);
    }
    
    /**
     * Перевірка чи містить текст ключове слово (з урахуванням морфології)
     */
    public function contains_keyword($text, $keyword, $threshold = 0.7) {
        $text = $this->normalize_text($text);
        $keyword = $this->normalize_text($keyword);
        
        $text_words = $this->tokenize($text);
        $keyword_words = $this->tokenize($keyword);
        
        // Точне співпадіння
        if (mb_strpos($text, $keyword, 0, 'UTF-8') !== false) {
            return true;
        }
        
        // Перевірка кожного слова з ключового слова
        foreach ($keyword_words as $kw) {
            $found = false;
            
            foreach ($text_words as $tw) {
                $similarity = $this->word_similarity($tw, $kw);
                
                if ($similarity >= $threshold) {
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Отримання ваги ключового слова в тексті
     */
    public function get_keyword_weight($text, $keyword) {
        $similarity = $this->calculate_similarity($text, $keyword);
        
        // Бонус за точне співпадіння
        if (mb_strpos($this->normalize_text($text), $this->normalize_text($keyword), 0, 'UTF-8') !== false) {
            $similarity = min($similarity * 1.5, 1.0);
        }
        
        return $similarity;
    }
}
