import { Injectable } from '@angular/core';
import { ApiService } from './api-service';
import { Router } from '@angular/router';
import { Observable } from 'rxjs';

@Injectable({
  providedIn: 'root'
})
export class AuthService {
    constructor(
        private api: ApiService,
        private router: Router
      ) {}

      login(email: string, password: string): Observable<any> {
        const formData = new FormData();
        formData.append('email', email);
        formData.append('password', password);
        return this.api.post<any>('controllers/login.controller.php', formData);
      }

      saveSession(user: any): void {
        localStorage.setItem('token', user.token || '');
        localStorage.setItem('idUsuario', user.idUsuario);
        localStorage.setItem('idRol', user.idRol);
        localStorage.setItem('email', user.email);
        localStorage.setItem('nombres', user.nombres);
        localStorage.setItem('apellidos', user.apellidos);
      }

      logout(): void {
        localStorage.removeItem('token');
        localStorage.removeItem('idUsuario');
        localStorage.removeItem('idRol');
        localStorage.removeItem('email');
        localStorage.removeItem('nombres');
        localStorage.removeItem('apellidos');
        this.router.navigate(['/login']);
      }

      isAuthenticated(): boolean {
        return !!localStorage.getItem('token');
      }

      getToken(): string | null {
        return localStorage.getItem('token');
      }

      getUserInfo(): any {
        return {
          idUsuario: localStorage.getItem('idUsuario'),
          idRol: localStorage.getItem('idRol'),
          email: localStorage.getItem('email'),
          nombres: localStorage.getItem('nombres'),
          apellidos: localStorage.getItem('apellidos')
        };
      }
    }
