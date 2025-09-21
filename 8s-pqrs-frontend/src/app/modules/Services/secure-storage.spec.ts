import { TestBed } from '@angular/core/testing';

import { SecureStorage } from './secure-storage';

describe('SecureStorage', () => {
  let service: SecureStorage;

  beforeEach(() => {
    TestBed.configureTestingModule({});
    service = TestBed.inject(SecureStorage);
  });

  it('should be created', () => {
    expect(service).toBeTruthy();
  });
});
