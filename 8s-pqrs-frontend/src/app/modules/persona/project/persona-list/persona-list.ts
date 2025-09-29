// import { Component } from '@angular/core';

// @Component({
//   selector: 'app-persona-list',
//   imports: [],
//   templateUrl: './persona-list.html',
//   styleUrl: './persona-list.scss'
// })
// export class PersonaList {

// }


import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

/* PrimeNG 20 */
import { TableModule } from 'primeng/table';
import { ButtonModule } from 'primeng/button';
import { InputTextModule } from 'primeng/inputtext';
import { DialogModule } from 'primeng/dialog';
import { TooltipModule } from 'primeng/tooltip';
import { TagModule } from 'primeng/tag';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { ConfirmationService } from 'primeng/api';

/* Tu helper HTTP */
import { ApiService } from '@/modules/Services/api-service';

/* ====== Modelo base (ajústalo si tu tabla tiene más/menos campos) ====== */
export interface Persona {
  idPersona: number;
  cedula?: string | null;   // cédula/RUC/DNI
  nombres: string;
  apellidos: string;
  celular?: string | null;
  email?: string | null;
  estado?: 'Activo' | 'Inactivo';
  fechaCreacion?: string;      // ISO o 'YYYY-MM-DD HH:mm:ss'
}

@Component({
  standalone: true,
  selector: 'app-persona-list',
  templateUrl: './persona-list.html',

  imports: [
    CommonModule, FormsModule,
    TableModule, ButtonModule, InputTextModule,
    DialogModule, TooltipModule, TagModule,
    ConfirmDialogModule
  ],
  providers: [ConfirmationService]
})
export class PersonaList implements OnInit {

  /* ====== tabla ====== */
  loading = false;
  personasAll: Persona[] = [];
  globalFilter = '';

  /* ====== diálogo alta/edición ====== */
  showDialog = false;
  dialogTitle = 'Nueva Persona';
  isEdit = false;

  form: Partial<Persona> = {
    idPersona: 0,
    cedula: '',
    nombres: '',
    apellidos: '',
    celular: '',
    email: '',
    estado: 'Activo'
  };

  constructor(
    private api: ApiService,
    private confirm: ConfirmationService
  ) { }

  ngOnInit(): void {
    this.load();
  }

  /* ======================= API ======================= */
  // Usa siempre ruta absoluta (tu ApiService suele anteponer /api)
  private ctrl = '/controllers/personas.controller.php';

  // Normaliza cualquier forma de respuesta {data:...} o array directo
  private unwrap<T>(res: any): T {
    return (res && Array.isArray(res.data)) ? (res.data as T) : (res as T);
  }

  load(): void {
    this.loading = true;
    this.api.get(this.ctrl, { op: 'todos' })
      .subscribe({
        next: (res: any) => {
          const rows = this.unwrap<Persona[]>(res);
          this.personasAll = Array.isArray(rows) ? rows : []//;
          // estado: (String((rows as any).estado) === '1' ? 'Activo' : 'Inactivo');
          this.loading = false;
        },
        error: (e) => {
          console.error('Error listando personas', e);
          this.loading = false;
          alert('No fue posible cargar personas (ver consola).');
        }
      });
  }

  getEstadoTexto(estado: number): string {
    return estado === 1 ? 'ACTIVO' : 'INACTIVO';
  }

  save(): void {
    // El controller usa: insertar | actualizar
    const op = this.isEdit ? 'actualizar' : 'insertar';

    // Mapear nombres que espera el backend
    const estadoNum = (this.form.estado ?? 'Activo') === 'Activo' ? 1 : 0;
    const payload: any = {
      idPersona: this.form.idPersona,                 // requerido en actualizar
      cedula: (this.form.cedula ?? '').trim(),     // controller lo llama 'cedula'
      nombres: (this.form.nombres ?? '').trim(),
      apellidos: (this.form.apellidos ?? '').trim(),
      direccion: '',                                  // campos no presentes en el form
      telefono: '',                                   // → se envían vacíos
      extension: '',
      celular: (this.form.celular ?? '').trim(),
      email: (this.form.email ?? '').trim(),
      estado: estadoNum
    };

    if (!payload.cedula || !payload.nombres || !payload.apellidos || !payload.email) {
      alert('Cédula, Nombres, Apellidos y E-mail son obligatorios.');
      return;
    }

    this.api.post(`${this.ctrl}?op=${op}`, payload).subscribe({
      next: (r: any) => {
        if (r?.message && r?.message.toString().toLowerCase().includes('error')) {
          alert(r?.message);
          return;
        }
        this.showDialog = false;
        this.load();
      },
      error: (e) => {
        console.error('Error guardando persona', e);
        alert('Error guardando la persona.');
      }
    });
  }

  askDelete(row: Persona): void {
    this.confirm.confirm({
      header: 'Eliminar persona',
      message: `¿Eliminar a <b>${row.nombres} ${row.apellidos}</b>? (si tiene usuario vinculado, el backend lo impedirá)`,
      icon: 'pi pi-exclamation-triangle',
      acceptLabel: 'Sí, eliminar',
      rejectLabel: 'Cancelar',
      accept: () => this.hardDelete(row),
    });
  }

  // El controller expone 'eliminar' (no 'desactivar')
  private hardDelete(row: Persona): void {
    this.api.post(`${this.ctrl}?op=eliminar`, { idPersona: row.idPersona })
      .subscribe({
        next: (r: any) => {
          // Si el backend responde 409 con mensaje de vinculación, ApiService lo pasará por error.
          if (r?.message && r?.message.toString().toLowerCase().includes('error')) {
            alert(r?.message);
            return;
          }
          this.load();
        },
        error: (e) => {
          console.error('Error eliminando persona', e);
          // Mensaje más amable cuando es 409 (vínculo con usuarios)
          if (e?.status === 409) {
            alert(e?.error?.message ?? 'No se puede eliminar: persona vinculada con usuarios.');
          } else {
            alert('Error eliminando la persona.');
          }
        }
      });
  }

  private softDelete(row: Persona): void {
    this.api.post(`${this.ctrl}?op=desactivar`, { idPersona: row.idPersona })
      .subscribe({
        next: (r: any) => {
          if (r?.ok === false) {
            alert(r?.error ?? 'No fue posible desactivar.');
            return;
          }
          this.load();
        },
        error: (e) => {
          console.error('Error desactivando persona', e);
          alert('Error desactivando la persona.');
        }
      });
  }

  /* ======================= UI helpers ======================= */
  openNew(): void {
    this.isEdit = false;
    this.dialogTitle = 'Nueva Persona';
    this.form = {
      idPersona: 0,
      cedula: '',
      nombres: '',
      apellidos: '',
      celular: '',
      email: '',
      estado: 'Activo'
    };
    this.showDialog = true;
  }

  openEdit(row: Persona): void {
    this.isEdit = true;
    this.dialogTitle = 'Editar Persona';
    this.form = { ...row };
    this.showDialog = true;
  }

  estadoSeverity(estado?: string) {
    const e = estado === '1' ? 'Activo' : 'Inactivo';
    return e;
  }
}
