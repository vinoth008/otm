package com.expensetracker.service;

import com.expensetracker.model.Transaction;
import org.apache.poi.ss.usermodel.*;
import org.apache.poi.xssf.usermodel.XSSFWorkbook;

import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.time.format.DateTimeFormatter;
import java.util.List;

/**
 * ReportService — Generates CSV and Excel reports from transaction data.
 */
public class ReportService {

    private static final DateTimeFormatter DATE_FORMAT =
        DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm");

    /**
     * Generate a CSV report of transactions.
     * @return CSV content as a byte array
     */
    public byte[] generateCsvReport(List<Transaction> transactions) throws IOException {
        StringBuilder sb = new StringBuilder();
        sb.append("ID,Type,Amount,Category,Wallet,Payment Method,Merchant,Description,Date,Tags\n");

        for (Transaction t : transactions) {
            sb.append(escapeCsv(t.getId())).append(',');
            sb.append(escapeCsv(t.getType())).append(',');
            sb.append(t.getAmount()).append(',');
            sb.append(escapeCsv(t.getCategory())).append(',');
            sb.append(escapeCsv(t.getWalletId())).append(',');
            sb.append(escapeCsv(t.getPaymentMethod())).append(',');
            sb.append(escapeCsv(t.getMerchant())).append(',');
            sb.append(escapeCsv(t.getDescription())).append(',');
            sb.append(t.getDate() != null ? t.getDate().format(DATE_FORMAT) : "").append(',');
            sb.append(escapeCsv(String.join(";", t.getTags() != null ? t.getTags() : List.of())));
            sb.append('\n');
        }

        return sb.toString().getBytes(StandardCharsets.UTF_8);
    }

    /**
     * Generate an Excel (.xlsx) report of transactions.
     * @return Excel file content as a byte array
     */
    public byte[] generateExcelReport(List<Transaction> transactions) throws IOException {
        try (Workbook workbook = new XSSFWorkbook();
             ByteArrayOutputStream baos = new ByteArrayOutputStream()) {

            Sheet sheet = workbook.createSheet("Transactions");

            // Header row
            String[] headers = {"ID", "Date", "Amount", "Type", "Category",
                "Wallet", "Payment Method", "Merchant", "Description", "Tags"};
            Row headerRow = sheet.createRow(0);
            CellStyle headerStyle = workbook.createCellStyle();
            Font headerFont = workbook.createFont();
            headerFont.setBold(true);
            headerStyle.setFont(headerFont);

            for (int i = 0; i < headers.length; i++) {
                Cell cell = headerRow.createCell(i);
                cell.setCellValue(headers[i]);
                cell.setCellStyle(headerStyle);
            }

            // Data rows
            int rowNum = 1;
            for (Transaction t : transactions) {
                Row row = sheet.createRow(rowNum++);
                row.createCell(0).setCellValue(t.getId() != null ? t.getId() : "");
                row.createCell(1).setCellValue(
                    t.getDate() != null ? t.getDate().format(DATE_FORMAT) : "");
                row.createCell(2).setCellValue(t.getAmount());
                row.createCell(3).setCellValue(t.getType() != null ? t.getType() : "");
                row.createCell(4).setCellValue(t.getCategory() != null ? t.getCategory() : "");
                row.createCell(5).setCellValue(t.getWalletId() != null ? t.getWalletId() : "");
                row.createCell(6).setCellValue(t.getPaymentMethod() != null ? t.getPaymentMethod() : "");
                row.createCell(7).setCellValue(t.getMerchant() != null ? t.getMerchant() : "");
                row.createCell(8).setCellValue(t.getDescription() != null ? t.getDescription() : "");
                row.createCell(9).setCellValue(
                    String.join("; ", t.getTags() != null ? t.getTags() : List.of()));
            }

            // Auto-size columns
            for (int i = 0; i < headers.length; i++) {
                sheet.autoSizeColumn(i);
            }

            workbook.write(baos);
            return baos.toByteArray();
        }
    }

    /**
     * Write a CSV report to an output stream.
     */
    public void writeCsvReport(List<Transaction> transactions, OutputStream out)
            throws IOException {
        out.write(generateCsvReport(transactions));
    }

    /**
     * Write an Excel report to an output stream.
     */
    public void writeExcelReport(List<Transaction> transactions, OutputStream out)
            throws IOException {
        out.write(generateExcelReport(transactions));
    }

    /**
     * Escape a value for CSV output.
     */
    private String escapeCsv(String value) {
        if (value == null) return "";
        if (value.contains(",") || value.contains("\"") || value.contains("\n")) {
            return "\"" + value.replace("\"", "\"\"") + "\"";
        }
        return value;
    }
}