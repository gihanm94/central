package com.acme.crm.entity.contact;

import com.acme.core.audit.BaseAudit;
import com.acme.crm.payload.contact.ContactDTO;
import lombok.AllArgsConstructor;
import lombok.Data;
import lombok.EqualsAndHashCode;
import lombok.NoArgsConstructor;
import lombok.experimental.SuperBuilder;
import org.springframework.data.annotation.Id;
import org.springframework.data.relational.core.mapping.Table;
import org.springframework.data.relational.core.query.Update;
import org.springframework.data.relational.core.sql.SqlIdentifier;

import java.util.HashMap;
import java.util.Map;

@Data
@AllArgsConstructor
@NoArgsConstructor
@SuperBuilder
@Table("contacts")
@EqualsAndHashCode(callSuper = true)
public class Contact extends BaseAudit {

  @Id
  private Long id;
  private String code;
  private String salutation;
  private String nameEn;
  private String nameTh;

  private String phone;
  private String phoneExt;
  private String mobile;
  private String mobileExt;
  private String fax;
  private String email;

  private String jobTitle;
  private String department;
  private String description;
  private Long leadSourceId;
  private Long leadId;
  private String avatar;

  private String address;
  private String city;
  private String province;
  private String country;
  private String zipcode;

  private String additionalContact;
  private Boolean isHaveContact;

  public static Contact buildCreateFromDTO(ContactDTO dto) {
    return Contact.builder()
        .code(dto.getCode())
        .salutation(dto.getSalutation())
        .nameEn(dto.getNameEn())
        .nameTh(dto.getNameTh())
        .phone(dto.getPhone())
        .phoneExt(dto.getPhoneExt())
        .mobile(dto.getMobile())
        .mobileExt(dto.getMobileExt())
        .fax(dto.getFax())
        .email(dto.getEmail())
        .jobTitle(dto.getJobTitle())
        .department(dto.getDepartment())
        .description(dto.getDescription())
        .leadId(dto.getLeadId())
        .avatar(dto.getAvatar())
        .address(dto.getAddress())
        .city(dto.getCity())
        .province(dto.getProvince())
        .country(dto.getCountry())
        .zipcode(dto.getZipcode())
        .additionalContact(dto.getAdditionalContact())
        .build();
  }

  public static Update buildUpdateFromDTO(ContactDTO dto) {
    Map<SqlIdentifier, Object> params = new HashMap<>();
    addIfNotNull(params, "code",        dto.getCode());
    addIfNotNull(params, "salutation",  dto.getSalutation());
    addIfNotNull(params, "name_en",     dto.getNameEn());
    addIfNotNull(params, "name_th",     dto.getNameTh());
    addIfNotNull(params, "phone",       dto.getPhone());
    addIfNotNull(params, "phone_ext",   dto.getPhoneExt());
    addIfNotNull(params, "mobile",      dto.getMobile());
    addIfNotNull(params, "mobile_ext",  dto.getMobileExt());
    addIfNotNull(params, "fax",         dto.getFax());
    addIfNotNull(params, "email",       dto.getEmail());
    addIfNotNull(params, "job_title",   dto.getJobTitle());
    addIfNotNull(params, "department",  dto.getDepartment());
    addIfNotNull(params, "description", dto.getDescription());
    addIfNotNull(params, "lead_id",     dto.getLeadId());
    addIfNotNull(params, "avatar",      dto.getAvatar());
    addIfNotNull(params, "address",     dto.getAddress());
    addIfNotNull(params, "city",        dto.getCity());
    addIfNotNull(params, "province",    dto.getProvince());
    addIfNotNull(params, "country",     dto.getCountry());
    addIfNotNull(params, "zipcode",     dto.getZipcode());
    addIfNotNull(params, "additional_contact", dto.getAdditionalContact());
    return Update.from(params);
  }
  private static void addIfNotNull(Map<SqlIdentifier, Object> params, String field, Object value) {
    if (value != null) params.put(SqlIdentifier.quoted(field), value);
  }
}