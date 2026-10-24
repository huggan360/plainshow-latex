class TextOperation {
    constructor() {
        this.ops = [];
        this.baseLength = 0;
        this.targetLength = 0;
    }
    retain(n) {
        if (n === 0) return this;
        this.baseLength += n;
        this.targetLength += n;
        if (typeof this.ops[this.ops.length - 1] === 'number' && this.ops[this.ops.length - 1] > 0) {
            this.ops[this.ops.length - 1] += n;
        } else {
            this.ops.push(n);
        }
        return this;
    }
    insert(str) {
        if (str === '') return this;
        this.targetLength += str.length;
        const last = this.ops[this.ops.length - 1];
        if (typeof last === 'string') {
            this.ops[this.ops.length - 1] += str;
        } else if (typeof last === 'number' && last < 0) {
            this.ops.splice(this.ops.length - 1, 0, str);
        } else {
            this.ops.push(str);
        }
        return this;
    }
    delete(n) {
        if (typeof n === 'string') n = n.length;
        if (n === 0) return this;
        this.baseLength += n;
        if (typeof this.ops[this.ops.length - 1] === 'number' && this.ops[this.ops.length - 1] < 0) {
            this.ops[this.ops.length - 1] -= n;
        } else {
            this.ops.push(-n);
        }
        return this;
    }
    isNoop() {
        return this.ops.length === 0 || (this.ops.length === 1 && typeof this.ops[0] === 'number' && this.ops[0] > 0);
    }
    static transform(op1, op2) {
        if (op1.baseLength !== op2.baseLength) throw new Error('Operations have different base lengths.');
        let op1prime = new TextOperation();
        let op2prime = new TextOperation();
        let i1 = 0, i2 = 0;
        let op1_offset = 0, op2_offset = 0;

        while (i1 < op1.ops.length || i2 < op2.ops.length) {
            let op1_item = i1 < op1.ops.length ? op1.ops[i1] : null;
            let op2_item = i2 < op2.ops.length ? op2.ops[i2] : null;

            if (typeof op1_item === 'string') {
                op1prime.insert(op1_item);
                op2prime.retain(op1_item.length);
                i1++;
                continue;
            }
            if (typeof op2_item === 'string') {
                op1prime.retain(op2_item.length);
                op2prime.insert(op2_item);
                i2++;
                continue;
            }
            if (op1_item === null || op2_item === null) {
                throw new Error("Cannot transform operations: run out of ops");
            }
            let op1_len = op1_item > 0 ? op1_item : -op1_item;
            let op2_len = op2_item > 0 ? op2_item : -op2_item;
            
            let rem1 = op1_len - op1_offset;
            let rem2 = op2_len - op2_offset;
            let min_len = Math.min(rem1, rem2);
            
            if (op1_item > 0 && op2_item > 0) {
                op1prime.retain(min_len);
                op2prime.retain(min_len);
            } else if (op1_item < 0 && op2_item > 0) {
                op1prime.delete(min_len);
            } else if (op1_item > 0 && op2_item < 0) {
                op2prime.delete(min_len);
            } else if (op1_item < 0 && op2_item < 0) {
                // both delete: noop
            }
            
            op1_offset += min_len;
            op2_offset += min_len;
            
            if (op1_offset === op1_len) { i1++; op1_offset = 0; }
            if (op2_offset === op2_len) { i2++; op2_offset = 0; }
        }
        return [op1prime, op2prime];
    }
    
    static compose(first, second) {
        if (first.targetLength !== second.baseLength) throw new Error('Cannot compose mismatched operations.');
        const result = new TextOperation();
        const a = first.ops.slice(), b = second.ops.slice();
        let i = 0, j = 0;
        while (i < a.length || j < b.length) {
            if (typeof a[i] === 'number' && a[i] < 0) { result.delete(-a[i++]); continue; }
            if (typeof b[j] === 'string') { result.insert(b[j++]); continue; }
            if (i >= a.length || j >= b.length) throw new Error('Incomplete composed operation.');
            const inserted = typeof a[i] === 'string';
            const n = Math.min(inserted ? a[i].length : a[i], Math.abs(b[j]));
            if (b[j] > 0) {
                if (inserted) result.insert(a[i].slice(0, n));
                else result.retain(n);
            } else if (!inserted) result.delete(n);
            a[i] = inserted ? a[i].slice(n) : a[i] - n;
            b[j] += b[j] > 0 ? -n : n;
            if (a[i] === '' || a[i] === 0) i++;
            if (b[j] === 0) j++;
        }
        return result;
    }

    apply(str) {
        if (str.length !== this.baseLength) {
            throw new Error("The operation's base length must be equal to the string's length.");
        }
        let newStr = [];
        let strIndex = 0;
        for (let op of this.ops) {
            if (typeof op === 'string') {
                newStr.push(op);
            } else if (op > 0) {
                newStr.push(str.slice(strIndex, strIndex + op));
                strIndex += op;
            } else {
                strIndex -= op;
            }
        }
        return newStr.join('');
    }
    static fromJSON(opsData) {
        if (!Array.isArray(opsData)) throw new Error('Invalid operation.');
        let op = new TextOperation();
        for (let item of opsData) {
            if (typeof item === 'number') {
                if (!Number.isSafeInteger(item)) throw new Error('Invalid operation length.');
                if (item > 0) op.retain(item);
                else op.delete(-item);
            } else if (typeof item === 'string') {
                op.insert(item);
            } else {
                throw new Error('Invalid operation component.');
            }
        }
        return op;
    }
    toJSON() {
        return this.ops;
    }
}
window.TextOperation = TextOperation;
