import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { RouterModule } from '@angular/router';

import { MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { IconFieldModule } from 'primeng/iconfield';
import { InputIconModule } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';
import { ProgressBarModule } from 'primeng/progressbar';
import { TooltipModule } from 'primeng/tooltip';

import * as XLSX from 'xlsx';
import { firstValueFrom } from 'rxjs';

import { AtencionesService, UpsertBody } from '@/modules/Services/atenciones-service';

type AtencionRow = UpsertBody;

/* ====== Helpers de normalización (nivel de módulo) ====== */
const stripDiacritics = (s: string) =>
    s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');

const normalizeKey = (s: any) => {
    const base = stripDiacritics(String(s || '').trim().toLowerCase());
    // quita espacios, guiones y guiones bajos
    return base.replace(/[\s\-_]+/g, '');
};

const pad2 = (n: number) => String(n).padStart(2, '0');

const toISOFromDate = (d: Date) =>
    `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

/** A YYYY-MM-DD desde string/number/Date (acepta datetime); null si no se puede */
const toISODate = (val: any): string | null => {
    if (val == null || val === '') return null;

    // 1) Date nativa
    if (val instanceof Date && !isNaN(val as any)) {
        return toISOFromDate(val);
    }

    // 2) Serial numérico Excel
    if (typeof val === 'number') {
        const d = (XLSX as any).SSF?.parse_date_code?.(val);
        if (d && d.y && d.m && d.d) return `${d.y}-${pad2(d.m)}-${pad2(d.d)}`;
    }

    // 3) String
    if (typeof val === 'string') {
        const s = val.trim();

        // 3.1 ISO date puro
        if (/^\d{4}-\d{2}-\d{2}$/.test(s) && s !== '0000-00-00') return s;

        // 3.2 ISO datetime -> toma solo la fecha
        const isoDt = /^(\d{4}-\d{2}-\d{2})[ T]\d{2}:\d{2}(:\d{2})?$/;
        const mIsoDt = isoDt.exec(s);
        if (mIsoDt && mIsoDt[1] !== '0000-00-00') return mIsoDt[1];

        // 3.3 dd/mm/yyyy (con o sin hora)
        const dmy = /^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})(?:\s+\d{1,2}:\d{2}(:\d{2})?)?$/;
        const mDmy = dmy.exec(s);
        if (mDmy) {
            let a = +mDmy[1], b = +mDmy[2], c = +mDmy[3];
            if (c < 100) c = 2000 + c;
            // Heurística por magnitud para decidir dd/mm o mm/dd
            const day = (a > 12 && b <= 12) ? a : (b > 12 && a <= 12) ? b : a;
            const month = (a > 12 && b <= 12) ? b : (b > 12 && a <= 12) ? a : b;
            if (month < 1 || month > 12 || day < 1 || day > 31) return null;
            return `${c}-${pad2(month)}-${pad2(day)}`;
        }
    }

    return null;
};

@Component({
    selector: 'app-atencion-list',
    standalone: true,
    imports: [
        CommonModule,
        RouterModule,
        TableModule,
        ButtonModule,
        ToastModule,
        ConfirmDialogModule,
        InputTextModule,
        IconFieldModule,
        InputIconModule,
        ProgressBarModule,
        TooltipModule
    ],
    templateUrl: './atencion-list.html',
    styleUrls: ['./atencion-list.scss'],
    providers: [MessageService]
})
export class AtencionList implements OnInit {
    atencionesAll: any[] = [];
    loading = true;

    importing = false;
    progress = 0;
    okCount = 0;
    errCount = 0;
    excelFirstDataRow = 2;

    constructor(
        private atencionesService: AtencionesService,
        private messageService: MessageService
    ) { }

    ngOnInit(): void { this.cargarAtenciones(); }

    cargarAtenciones(): void {
        this.loading = true;
        this.atencionesService.listAll().subscribe({
            next: (data: any[]) => { this.atencionesAll = Array.isArray(data) ? data : []; this.loading = false; },
            error: (e) => {
                const raw = e?.error;
                const msg = (typeof raw === 'string' && raw.startsWith('<!DOCTYPE'))
                    ? 'El front recibió HTML en lugar de JSON (revisa que ApiService apunte al backend).'
                    : (e?.error?.message || e?.message || 'No se pudo cargar las atenciones');
                this.messageService.add({ severity: 'error', summary: 'Error', detail: msg });
                this.loading = false;
            }
        });
    }

    onGlobalFilter(table: any, event: Event) {
        const input = (event.target as HTMLInputElement).value;
        table.filterGlobal(input, 'contains');
    }
    clearFilter() { this.atencionesAll = [...this.atencionesAll]; }
    verDetalle(_: any) { }
    editarAtencion(_: any) { }
    eliminarAtencion(_: any) { }

    // ==== IMPORT EXCEL ====
    triggerFilePicker() { (document.getElementById('excelInput') as HTMLInputElement)?.click(); }

    async onFileChange(ev: Event) {
        const input = ev.target as HTMLInputElement;
        if (!input.files || input.files.length === 0) return;
        const file = input.files[0];
        input.value = '';

        try {
            const rows = await this.readExcel(file);
            if (!rows.length) {
                this.messageService.add({ severity: 'warn', summary: 'Sin filas', detail: 'El archivo no contiene datos válidos.' });
                return;
            }
            await this.doImport(rows);
            this.cargarAtenciones();
        } catch (e: any) {
            this.messageService.add({ severity: 'error', summary: 'Error leyendo Excel', detail: String(e?.message || e) });
        }
    }

    //   private async readExcel(file: File): Promise<AtencionRow[]> {
    //     const buf = await file.arrayBuffer();

    //     // Lee fechas como Date cuando aplique
    //     const wb = XLSX.read(buf, { type: 'array', cellDates: true });
    //     const sheet = wb.Sheets[wb.SheetNames[0]];

    //   // calcula en base al rango del sheet
    //   const ref = sheet['!ref'];
    //   if (ref) {
    //     const r = XLSX.utils.decode_range(ref); // 0-based
    //     this.excelFirstDataRow = (r.s.r + 2);   // encabezado en r.s.r+1 -> datos desde +2
    //   }


    //     // raw:true deja Date/number; defval:null evita undefined
    //     const json: any[] = XLSX.utils.sheet_to_json(sheet, {
    //       defval: null,
    //       raw: true,
    //       dateNF: 'yyyy-mm-dd'
    //     });

    //  const out: AtencionRow[] = [];
    //   for (const r of json) {
    //     const row: any = {};

    //     const mapKey = (k: string): keyof AtencionRow | null => {
    //       const n = normalizeKey(k);

    //       if (['idclienteerp','idcliente','clienteerp'].includes(n)) return 'idClienteErp';
    //       if (['cedula','documento','dni'].includes(n)) return 'cedula';
    //       if (['nombres','nombre'].includes(n)) return 'nombres';
    //       if (['apellidos','apellido'].includes(n)) return 'apellidos';
    //       if (['email','correo'].includes(n)) return 'email';
    //       if (['telefono','telefono1','tel'].includes(n)) return 'telefono';
    //       if (['celular','movil','movil1'].includes(n)) return 'celular';
    //       if (['idagencia','agenciaid','agencia'].includes(n)) return 'idAgencia';

    //       // Soporta "Fecha Atención", "fecha atencion", "fecha_atención", etc.
    //       if (['fechaatencion','fechaatención','fechadeatencion','fechadeatención','fecha','fecha_atencion'].includes(n)) return 'fechaAtencion';

    //       if (['numerodocumento','numdocumento','numero_documento','#documento','documentonumero','docnumero'].includes(n)) return 'numeroDocumento';
    //       if (['tipodocumento','doc_tipo','tipodoc','tipodocumento','tipo_documento'].includes(n)) return 'tipoDocumento';
    //       if (['numerofactura','factura','numero_factura'].includes(n)) return 'numeroFactura';
    //       if (['idcanal','canalid','canal'].includes(n)) return 'idCanal';
    //       if (['detalle','observacion','observación','observaciones'].includes(n)) return 'detalle';
    //       if (['cedulaasesor','asesorcedula','asesor'].includes(n)) return 'cedulaAsesor';

    //       return null;
    //     };

    //     const out: AtencionRow[] = [];

    //     for (const r of json) {
    //       const row: any = {};

    //       // Mapear encabezados -> claves esperadas por el backend
    //       Object.keys(r).forEach(k => {
    //         const dest = mapKey(k);
    //         if (!dest) return;
    //         let v = r[k];
    //         if (typeof v === 'string') v = v.trim();
    //         row[dest] = v;
    //       });

    //       // Normaliza fecha: acepta ISO, Date y serial Excel; si falla, null
    //       if (row.fechaAtencion != null && row.fechaAtencion !== '') {
    //         const iso = toISODate(row.fechaAtencion);
    //         row.fechaAtencion = iso || null;
    //       }
    //       if (row.fechaAtencion === '0000-00-00') row.fechaAtencion = null;

    //       // Normalizaciones numéricas seguras
    //       if (row.idAgencia != null && row.idAgencia !== '') {
    //         const n = Number(row.idAgencia);
    //         row.idAgencia = Number.isFinite(n) ? n : null;
    //       }
    //       if (row.idCanal != null && row.idCanal !== '') {
    //         const n = Number(row.idCanal);
    //         row.idCanal = Number.isFinite(n) ? n : null;
    //       }

    //     // Guarda la fila original si la da XLSX (__rowNum__ es 0-based)
    //     if (typeof r.__rowNum__ === 'number') {
    //       row.__row = r.__rowNum__ + 1; // 1-based como Excel
    //     }

    //       out.push(row as AtencionRow);
    //     }

    //     return out;
    //   }

    private async readExcel(file: File): Promise<AtencionRow[]> {
        const buf = await file.arrayBuffer();

        // Lee fechas como Date cuando aplique
        const wb = XLSX.read(buf, { type: 'array', cellDates: true });
        const sheet = wb.Sheets[wb.SheetNames[0]];

        // Detecta la primera fila de datos según el rango del sheet
        const ref = sheet['!ref'];
        if (ref) {
            const r = XLSX.utils.decode_range(ref); // 0-based
            this.excelFirstDataRow = r.s.r + 2;     // encabezado en r.s.r+1 -> datos desde +2
        }

        // Obtiene filas como objetos (manteniendo Date/number si aplica)
        const json: any[] = XLSX.utils.sheet_to_json(sheet, {
            defval: null,
            raw: true,
            dateNF: 'yyyy-mm-dd'
        });

        // Mapeo de encabezados normalizado
        const mapKey = (k: string): keyof AtencionRow | null => {
            const n = normalizeKey(k);
            if (['idclienteerp', 'idcliente', 'clienteerp'].includes(n)) return 'idClienteErp';
            if (['cedula', 'documento', 'dni'].includes(n)) return 'cedula';
            if (['nombres', 'nombre'].includes(n)) return 'nombres';
            if (['apellidos', 'apellido'].includes(n)) return 'apellidos';
            if (['email', 'correo'].includes(n)) return 'email';
            if (['telefono', 'telefono1', 'tel'].includes(n)) return 'telefono';
            if (['celular', 'movil', 'movil1'].includes(n)) return 'celular';
            if (['idagencia', 'agenciaid', 'agencia'].includes(n)) return 'idAgencia';
            if (['fechaatencion', 'fechaatención', 'fechadeatencion', 'fechadeatención', 'fecha', 'fecha_atencion'].includes(n)) return 'fechaAtencion';
            if (['numerodocumento', 'numdocumento', 'numero_documento', '#documento', 'documentonumero', 'docnumero'].includes(n)) return 'numeroDocumento';
            if (['tipodocumento', 'doc_tipo', 'tipodoc', 'tipodocumento', 'tipo_documento'].includes(n)) return 'tipoDocumento';
            if (['numerofactura', 'factura', 'numero_factura'].includes(n)) return 'numeroFactura';
            if (['idcanal', 'canalid', 'canal'].includes(n)) return 'idCanal';
            if (['detalle', 'observacion', 'observación', 'observaciones'].includes(n)) return 'detalle';
            if (['cedulaasesor', 'asesorcedula', 'asesor'].includes(n)) return 'cedulaAsesor';
            return null;
        };

        const out: AtencionRow[] = [];

        for (const r of json) {
            const row: any = {};

            // Mapear encabezados -> claves esperadas por el backend
            Object.keys(r).forEach(k => {
                const dest = mapKey(k);
                if (!dest) return;
                let v = r[k];
                if (typeof v === 'string') v = v.trim();
                row[dest] = v;
            });

            // Normaliza fecha: acepta ISO, Date y serial Excel; si falla, null
            if (row.fechaAtencion != null && row.fechaAtencion !== '') {
                const iso = toISODate(row.fechaAtencion);
                row.fechaAtencion = iso || null;
            }
            if (row.fechaAtencion === '0000-00-00') row.fechaAtencion = null;

            // Normalizaciones numéricas seguras
            if (row.idAgencia != null && row.idAgencia !== '') {
                const n = Number(row.idAgencia);
                row.idAgencia = Number.isFinite(n) ? n : null;
            }
            if (row.idCanal != null && row.idCanal !== '') {
                const n = Number(row.idCanal);
                row.idCanal = Number.isFinite(n) ? n : null;
            }

            // Guarda la fila original si XLSX la expone (__rowNum__ es 0-based)
            if (typeof r.__rowNum__ === 'number') {
                row.__row = r.__rowNum__ + 1; // 1-based como Excel
            }

            out.push(row as AtencionRow);
        }

        return out;
    }




    private filaInvalida(r: AtencionRow): string[] {
        const faltan: string[] = [];
        if (!r.cedula) faltan.push('cedula');
        if (!r.nombres) faltan.push('nombres');
        if (!r.apellidos) faltan.push('apellidos');
        if (!r.idAgencia && r.idAgencia !== 0) faltan.push('idAgencia');
        if (!r.fechaAtencion) faltan.push('fechaAtencion');
        if (!r.numeroDocumento) faltan.push('numeroDocumento');
        if (!r.tipoDocumento) faltan.push('tipoDocumento');
        return faltan;
    }

    // private async doImport(rows: AtencionRow[]) {
    //     this.importing = true;
    //     this.progress = 0; this.okCount = 0; this.errCount = 0;

    //     const total = rows.length;

    //     for (let i = 0; i < total; i++) {
    //         const r = rows[i];
    //         const cedForMsg = r?.cedula || '(sin cédula)';

    //         const faltan = this.filaInvalida(r);
    //         if (faltan.length) {
    //             this.errCount++;
    //             this.messageService.add({
    //                 severity: 'error',
    //                 summary: 'Error en fila',
    //                 detail: `${cedForMsg}: faltan campos [${faltan.join(', ')}]`,
    //                 life: 4500
    //             });
    //             this.progress = Math.round(((i + 1) / total) * 100);
    //             continue;
    //         }
    //         // Pre-validación del asesor (si viene)
    //         if (r.cedulaAsesor) {
    //             try {
    //                 const ver = await firstValueFrom(this.atencionesService.validarAsesor(r.cedulaAsesor));
    //                 if (!ver?.success || ver?.exists === false) {
    //                     this.errCount++;
    //                     this.messageService.add({
    //                         severity: 'error',
    //                         summary: 'Asesor no encontrado',
    //                         detail: `${cedForMsg}: La cédula del asesor no corresponde a ningún usuario (${r.cedulaAsesor})`,
    //                         life: 5500
    //                     });
    //                     this.progress = Math.round(((i + 1) / total) * 100);
    //                     continue; // no intentamos el upsert
    //                 }
    //             } catch (err: any) {
    //                 const msg = err?.error?.message || 'La cédula del asesor no corresponde a ningún usuario';
    //                 this.errCount++;
    //                 this.messageService.add({
    //                     severity: 'error',
    //                     summary: 'Asesor no encontrado',
    //                     detail: `${cedForMsg}: ${msg} (${r.cedulaAsesor})`,
    //                     life: 5500
    //                 });
    //                 this.progress = Math.round(((i + 1) / total) * 100);
    //                 continue;
    //             }
    //         }


    //         try {
    //             const res: any = await firstValueFrom(this.atencionesService.upsert(r));
    //             if (res?.success) {
    //                 this.okCount++;
    //                 this.messageService.add({
    //                     severity: 'success',
    //                     summary: 'Importado',
    //                     detail: `${r.cedula} - ${r.nombres} ${r.apellidos}`,
    //                     life: 2200
    //                 });
    //             } else {
    //                 this.errCount++;
    //                 const msg = res?.message || res?.error || 'Error desconocido';
    //                 this.messageService.add({
    //                     severity: 'error',
    //                     summary: 'Error en fila',
    //                     detail: `${cedForMsg}: ${msg}`,
    //                     life: 4500
    //                 });
    //             }
    //         } catch (e: any) {
    //             this.errCount++;

    //             let msg = '';
    //             if (e?.error) {
    //                 if (typeof e.error === 'string') {
    //                     msg = e.error;
    //                 } else if (typeof e.error === 'object') {
    //                     msg = e.error.message || JSON.stringify(e.error);
    //                     if (Array.isArray(e.error.missing) && e.error.missing.length) {
    //                         msg += ` | Faltan: [${e.error.missing.join(', ')}]`;
    //                     }
    //                 }
    //             }
    //             if (!msg) msg = e?.message || 'Error desconocido';

    //             console.error('Error upsert fila:', { payload: r, error: e });

    //             this.messageService.add({
    //                 severity: 'error',
    //                 summary: 'Error en fila',
    //                 detail: `${cedForMsg}: ${msg}`,
    //                 life: 6000
    //             });
    //         }

    //         this.progress = Math.round(((i + 1) / total) * 100);
    //         await new Promise(res => setTimeout(res, 15));
    //     }

    //     this.importing = false;
    //     this.messageService.add({
    //         severity: this.errCount ? 'warn' : 'success',
    //         summary: 'Importación finalizada',
    //         detail: `OK: ${this.okCount} · Errores: ${this.errCount}`,
    //         life: 4500
    //     });
    // }

    private async doImport(rows: AtencionRow[]) {
        this.importing = true;
        this.progress = 0; this.okCount = 0; this.errCount = 0;

        const total = rows.length;

        for (let i = 0; i < total; i++) {
            const r = rows[i] as any; // para acceder a __row sin quejarse TS
            const rowNo = (r?.__row ?? (this.excelFirstDataRow + i)); // 1-based
            const cedForMsg = r?.cedula || '(sin cédula)';

            // 1) Validación obligatorios
            const faltan = this.filaInvalida(r);
            if (faltan.length) {
                this.errCount++;
                this.messageService.add({
                    severity: 'error',
                    summary: 'Error en fila',
                    detail: `Fila ${rowNo} (${cedForMsg}): faltan campos [${faltan.join(', ')}]`,
                    life: 4500
                });
                this.progress = Math.round(((i + 1) / total) * 100);
                continue;
            }

            // 2) Pre-validación del asesor (si viene)
            if (r.cedulaAsesor) {
                try {
                    const ver = await firstValueFrom(this.atencionesService.validarAsesor(r.cedulaAsesor));
                    if (!ver?.success || ver?.exists === false) {
                        this.errCount++;
                        this.messageService.add({
                            severity: 'error',
                            summary: 'Asesor no encontrado',
                            detail: `Fila ${rowNo} (${cedForMsg}): La cédula del asesor no corresponde a ningún usuario (${r.cedulaAsesor})`,
                            life: 5500
                        });
                        this.progress = Math.round(((i + 1) / total) * 100);
                        continue;
                    }
                } catch (err: any) {
                    const msg = err?.error?.message || 'La cédula del asesor no corresponde a ningún usuario';
                    this.errCount++;
                    this.messageService.add({
                        severity: 'error',
                        summary: 'Asesor no encontrado',
                        detail: `Fila ${rowNo} (${cedForMsg}): ${msg} (${r.cedulaAsesor})`,
                        life: 5500
                    });
                    this.progress = Math.round(((i + 1) / total) * 100);
                    continue;
                }
            }

            // 3) Upsert
            try {
                const res: any = await firstValueFrom(this.atencionesService.upsert(r));
                if (res?.success) {
                    this.okCount++;
                    this.messageService.add({
                        severity: 'success',
                        summary: 'Importado',
                        detail: `Fila ${rowNo}: ${r.cedula} - ${r.nombres} ${r.apellidos}`,
                        life: 2200
                    });
                } else {
                    this.errCount++;
                    const msg = res?.message || res?.error || 'Error desconocido';
                    this.messageService.add({
                        severity: 'error',
                        summary: 'Error en fila',
                        detail: `Fila ${rowNo} (${cedForMsg}): ${msg}`,
                        life: 4500
                    });
                }
            } catch (e: any) {
                this.errCount++;
                let msg = '';
                if (e?.error) {
                    if (typeof e.error === 'string') msg = e.error;
                    else if (typeof e.error === 'object') {
                        msg = e.error.message || JSON.stringify(e.error);
                        if (Array.isArray(e.error.missing) && e.error.missing.length) {
                            msg += ` | Faltan: [${e.error.missing.join(', ')}]`;
                        }
                    }
                }
                if (!msg) msg = e?.message || 'Error desconocido';

                console.error('Error upsert fila:', { rowNo, payload: r, error: e });

                this.messageService.add({
                    severity: 'error',
                    summary: 'Error en fila',
                    detail: `Fila ${rowNo} (${cedForMsg}): ${msg}`,
                    life: 6000
                });
            }

            this.progress = Math.round(((i + 1) / total) * 100);
            await new Promise(res => setTimeout(res, 15));
        }

        this.importing = false;
        this.messageService.add({
            severity: this.errCount ? 'warn' : 'success',
            summary: 'Importación finalizada',
            detail: `OK: ${this.okCount} · Errores: ${this.errCount}`,
            life: 4500
        });
    }

}
