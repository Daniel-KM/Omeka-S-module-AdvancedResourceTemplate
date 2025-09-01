<?php declare(strict_types=1);

namespace AdvancedResourceTemplate\Stdlib;

class CountableAppendIterator extends \AppendIterator implements \Countable
{
    public function count(): int
    {
        $count = 0;
        foreach ($this as $item) {
            ++$count;
        }
        $this->rewind();
        return $count;
    }
}
