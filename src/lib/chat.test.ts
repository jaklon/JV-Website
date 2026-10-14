import { describe, expect, it } from 'vitest';
import { safeChatHref } from './chat';
describe('source links',()=>{
  it('accepts only existing JV Story source routes',()=>{for(const href of [`/kenangan/${'a'.repeat(32)}`,`/timeline?album=${'b'.repeat(32)}`,`/surat?letter=${'c'.repeat(32)}`,`/tanggal#date-${'d'.repeat(32)}`,'/pengaturan'])expect(safeChatHref(href)).toBe(true);});
  it('rejects links to outside sites and arbitrary application paths',()=>{for(const href of ['javascript:alert(1)','//evil.example','https://evil.example','/api/auth/logout','/surat?letter=../../api','/kenangan/a','/pengaturan?redirect=https://evil.example'])expect(safeChatHref(href)).toBe(false);});
});
