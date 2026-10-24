<?php
namespace App\OT;

class TextOperation {
    public $ops = [];
    public $baseLength = 0;
    public $targetLength = 0;

    public static function length($text) {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    public function retain($n) {
        if (!is_int($n) || $n < 0) throw new \InvalidArgumentException('Invalid retain length.');
        if ($n === 0) return $this;
        $this->baseLength += $n;
        $this->targetLength += $n;
        $last = end($this->ops);
        if (is_int($last) && $last > 0) {
            $this->ops[count($this->ops) - 1] += $n;
        } else {
            $this->ops[] = $n;
        }
        return $this;
    }

    public function insert($str) {
        if ($str === '') return $this;
        $this->targetLength += self::length($str);
        $last = end($this->ops);
        if (is_string($last)) {
            $this->ops[count($this->ops) - 1] .= $str;
        } else {
            // Canonical format: insert comes before delete
            if (is_int($last) && $last < 0) {
                array_splice($this->ops, count($this->ops) - 1, 0, [$str]);
            } else {
                $this->ops[] = $str;
            }
        }
        return $this;
    }

    public function delete($n) {
        if (is_string($n)) {
            $n = self::length($n);
        }
        if (!is_int($n) || $n < 0) throw new \InvalidArgumentException('Invalid delete length.');
        if ($n === 0) return $this;
        $this->baseLength += $n;
        $last = end($this->ops);
        if (is_int($last) && $last < 0) {
            $this->ops[count($this->ops) - 1] -= $n;
        } else {
            $this->ops[] = -$n;
        }
        return $this;
    }

    public static function transform(TextOperation $op1, TextOperation $op2) {
        if ($op1->baseLength !== $op2->baseLength) {
            throw new \Exception("The base length of the two operations is different.");
        }

        $op1prime = new TextOperation();
        $op2prime = new TextOperation();
        
        $i1 = 0; $i2 = 0;
        $op1_offset = 0; $op2_offset = 0;
        
        while ($i1 < count($op1->ops) || $i2 < count($op2->ops)) {
            $op1_item = $i1 < count($op1->ops) ? $op1->ops[$i1] : null;
            $op2_item = $i2 < count($op2->ops) ? $op2->ops[$i2] : null;
            
            if (is_string($op1_item)) {
                $op1prime->insert($op1_item);
                $op2prime->retain(self::length($op1_item));
                $i1++;
                continue;
            }
            if (is_string($op2_item)) {
                $op1prime->retain(self::length($op2_item));
                $op2prime->insert($op2_item);
                $i2++;
                continue;
            }
            
            if ($op1_item === null || $op2_item === null) {
                throw new \Exception("Cannot transform operations: run out of ops");
            }

            $op1_len = $op1_item > 0 ? $op1_item : -$op1_item;
            $op2_len = $op2_item > 0 ? $op2_item : -$op2_item;
            
            $rem1 = $op1_len - $op1_offset;
            $rem2 = $op2_len - $op2_offset;
            
            $min_len = min($rem1, $rem2);
            
            if ($op1_item > 0 && $op2_item > 0) {
                $op1prime->retain($min_len);
                $op2prime->retain($min_len);
            } elseif ($op1_item < 0 && $op2_item > 0) {
                $op1prime->delete($min_len);
            } elseif ($op1_item > 0 && $op2_item < 0) {
                $op2prime->delete($min_len);
            } elseif ($op1_item < 0 && $op2_item < 0) {
                // both delete, so no-op in prime
            }
            
            $op1_offset += $min_len;
            $op2_offset += $min_len;
            
            if ($op1_offset === $op1_len) {
                $i1++;
                $op1_offset = 0;
            }
            if ($op2_offset === $op2_len) {
                $i2++;
                $op2_offset = 0;
            }
        }
        
        return [$op1prime, $op2prime];
    }

    public function apply($str) {
        if (self::length($str) !== $this->baseLength) {
            throw new \Exception("The operation's base length must be equal to the string's length.");
        }
        $encoded = mb_convert_encoding($str, 'UTF-16LE', 'UTF-8');
        $newStr = [];
        $strIndex = 0;
        foreach ($this->ops as $op) {
            if (is_string($op)) {
                $newStr[] = mb_convert_encoding($op, 'UTF-16LE', 'UTF-8');
            } elseif ($op > 0) {
                $newStr[] = substr($encoded, $strIndex * 2, $op * 2);
                $strIndex += $op;
            } else {
                $strIndex -= $op; // ops are negative for delete
            }
        }
        $result = implode('', $newStr);
        if (!mb_check_encoding($result, 'UTF-16LE')) throw new \InvalidArgumentException('Operation splits a Unicode character.');
        return mb_convert_encoding($result, 'UTF-8', 'UTF-16LE');
    }
    
    public static function fromJSON($opsData) {
        if (!is_array($opsData) || count($opsData) > 100000) throw new \InvalidArgumentException('Invalid operation.');
        $op = new TextOperation();
        foreach ($opsData as $item) {
            if (is_int($item)) {
                if ($item > 0) $op->retain($item);
                else $op->delete(-$item);
            } elseif (is_string($item)) {
                $op->insert($item);
            } else {
                throw new \InvalidArgumentException('Invalid operation component.');
            }
        }
        return $op;
    }
    
    public function toJSON() {
        return $this->ops;
    }
}
