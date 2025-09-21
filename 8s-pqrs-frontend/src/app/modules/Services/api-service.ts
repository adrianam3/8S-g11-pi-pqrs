import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Injectable } from '@angular/core';
import { Router } from '@angular/router';
import { MessageService } from 'primeng/api';
import { catchError, Observable, throwError } from 'rxjs';
import { environment } from 'src/environments/environment';

@Injectable({
    providedIn: 'root'
})
export class ApiService {
    private apiUrl = environment.apiUrl; // URL base del backend

    constructor(private http: HttpClient,
        private messageService: MessageService,
        private router: Router
    ) { }

    private getSessionHeaders(): HttpHeaders {
        const idUsuario = localStorage.getItem('idUsuario') || '';
        const idRol = localStorage.getItem('idRol') || '';
        const token = localStorage.getItem('token') || '';

        return new HttpHeaders({
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
            'idUsuario': idUsuario,
            'idRol': idRol
        });
    }

    get<T>(endpoint: string, params?: any): Observable<T> {
        const headers = this.getSessionHeaders2();
        return this.http.get<T>(`${this.apiUrl}/${endpoint}`, { headers, params }).pipe(
            catchError((error) => this.handleError<T>(error))
        );
    }

    postLogin<T>(endpoint: string, data: any): Observable<any> {
        const headers = new HttpHeaders({
            'Content-Type': 'application/json',
        });

        return this.http.post<T>(`${this.apiUrl}/${endpoint}`, data, { headers }).pipe(
            catchError((error) => this.handleError<T>(error))
        );
    }

    post<T>(endpoint: string, data: any): Observable<any> {
        const headers = this.getSessionHeaders2(data);

        return this.http.post<T>(`${this.apiUrl}/${endpoint}`, data, { headers }).pipe(
            catchError((error) => this.handleError<T>(error))
        );
    }

    private getSessionHeaders2(data?: any): HttpHeaders {
        let headers = new HttpHeaders();

        // Si NO estás enviando FormData, usamos application/json
        if (!(data instanceof FormData)) {
            headers = headers.set('Content-Type', 'application/json');
        }

        // Aquí puedes añadir cabeceras de sesión/token
        const idUsuario = localStorage.getItem('idUsuario');
        const token = localStorage.getItem('token');

        console.log(idUsuario, token)
        if (idUsuario) headers = headers.set('idUsuario', idUsuario);
        if (token) headers = headers.set('Authorization', `Bearer ${token}`);

        return headers;
    }


    // Método genérico para peticiones POST
    post2<T>(endpoint: string, data: any): Observable<any> {
        return this.http.post<T>(`${this.apiUrl}/${endpoint}`, data).pipe(
            catchError(error => this.handleError<T>(error))
        );
    }

    postFormData<T>(endpoint: string, formData: FormData): Observable<T> {
        const idUsuario = localStorage.getItem('idUsuario') || '';
        const idRol = localStorage.getItem('idRol') || '';


        const headers = new HttpHeaders({
            'idUsuario': idUsuario,
            'idRol': idRol
        });


        return this.http.post<T>(`${this.apiUrl}/${endpoint}`, formData, { headers }).pipe(
            catchError((error) => this.handleError<T>(error))
        );
    }


    postData(operation: string, data: any): Observable<any> {
        const headers = this.getSessionHeaders();
        return this.http.post<any>(
            `${this.apiUrl}/controllers/ticket.controller.php?${operation}`,
            data,
            { headers, withCredentials: true }
        ).pipe(
            catchError((error) => this.handleError(error))
        );
    }

    put<T>(endpoint: string, data: any): Observable<T> {
        const headers = this.getSessionHeaders();
        return this.http.put<T>(`${this.apiUrl}/${endpoint}`, data, { headers }).pipe(
            catchError((error) => this.handleError<T>(error))
        );
    }


    delete<T>(endpoint: string): Observable<T> {
        const headers = this.getSessionHeaders();
        return this.http.delete<T>(`${this.apiUrl}/${endpoint}`, { headers }).pipe(
            catchError((error) => this.handleError<T>(error))
        );
    }


    private handleError<T>(error: HttpErrorResponse): Observable<T> {
        if (error.status === 401) {
            this.showToast('error', 'Sesión expirada', 'Por favor inicie sesión nuevamente.');
            localStorage.removeItem('token');
            localStorage.removeItem('idUsuario');
            localStorage.removeItem('idRol');
            this.router.navigate(['/login']);
        } else {
            console.error('Error en API:', error);
            if (error.error && error.error.message) {
                this.showToast('error', 'Error', error.error.message);
            } else {
                this.showToast('error', 'Error', 'Ocurrió un error inesperado.');
            }
        }
        return throwError(() => new Error(error.message));
    }


    public showToast(severity: 'success' | 'info' | 'warn' | 'error', summary: string, detail: string): void {
        this.messageService.add({ severity, summary, detail });
    }


    createFormData(data: { [key: string]: any }): FormData {
        const formData = new FormData();
        for (const key in data) {
            if (data.hasOwnProperty(key)) {
                const value = data[key];
                formData.append(key, value != null ? value : '');
            }
        }
        return formData;
    }
}
