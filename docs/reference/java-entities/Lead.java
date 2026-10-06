package com.acme.crm.entity.lead;

import com.acme.core.audit.BaseAudit;
import com.acme.crm.payload.lead.LeadDTO;
import lombok.AllArgsConstructor;
import lombok.Data;
import lombok.EqualsAndHashCode;
import lombok.NoArgsConstructor;
import lombok.experimental.SuperBuilder;
import org.springframework.data.annotation.Id;
import org.springframework.data.relational.core.mapping.Table;
import org.springframework.data.relational.core.query.Update;
import org.springframework.data.relational.core.sql.SqlIdentifier;

import java.math.BigDecimal;
import java.util.HashMap;
import java.util.Map;

@Data
@AllArgsConstructor
@NoArgsConstructor
@SuperBuilder
@Table("leads")
@EqualsAndHashCode(callSuper = true)
public class Lead extends BaseAudit {

  @Id
  private Long id;

  private String code;
  private String nameEn;
  private String nameTh;
  private String taxId;
  private BigDecimal revenue;
  private String phone;
  private String mobile;
  private String fax;
  private String address;
  private String city;
  private String province;
  private String country;
  private String zipcode;
  private String description;
  private String image;

  private Boolean isRegister;
  private Boolean isGovernment;
  private Long companyId;

  public static Lead buildCreateFromDTO(LeadDTO dto) {
    return Lead.builder()
        .code(dto.getCode())
        .nameEn(dto.getNameEn())
        .nameTh(dto.getNameTh())
        .taxId(dto.getTaxId())
        .revenue(dto.getRevenue())
        .phone(dto.getPhone())
        .mobile(dto.getMobile())
        .fax(dto.getFax())
        .address(dto.getAddress())
        .city(dto.getCity())
        .province(dto.getProvince())
        .country(dto.getCountry())
        .zipcode(dto.getZipcode())
        .description(dto.getDescription())
        .image(dto.getImage())
        .isRegister(dto.getIsRegister() != null ? dto.getIsRegister() : Boolean.FALSE)
        .isGovernment(dto.getIsGovernment() != null ? dto.getIsGovernment() : Boolean.FALSE)
        .companyId(dto.getCompanyId())
        .build();
  }

  public static Update buildUpdateFromDTO(LeadDTO dto) {
    Map<SqlIdentifier, Object> params = new HashMap<>();
    addIfNotNull(params, "code",          dto.getCode());
    addIfNotNull(params, "name_en",       dto.getNameEn());
    addIfNotNull(params, "name_th",       dto.getNameTh());
    addIfNotNull(params, "tax_id",        dto.getTaxId());
    addIfNotNull(params, "revenue",       dto.getRevenue());
    addIfNotNull(params, "phone",         dto.getPhone());
    addIfNotNull(params, "mobile",        dto.getMobile());
    addIfNotNull(params, "fax",           dto.getFax());
    addIfNotNull(params, "address",       dto.getAddress());
    addIfNotNull(params, "city",          dto.getCity());
    addIfNotNull(params, "province",      dto.getProvince());
    addIfNotNull(params, "country",       dto.getCountry());
    addIfNotNull(params, "zipcode",       dto.getZipcode());
    addIfNotNull(params, "description",   dto.getDescription());
    addIfNotNull(params, "image",         dto.getImage());
    addIfNotNull(params, "is_register",   dto.getIsRegister());
    addIfNotNull(params, "is_government", dto.getIsGovernment());
    addIfNotNull(params, "company_id",    dto.getCompanyId());
    return Update.from(params);
  }

  private static void addIfNotNull(Map<SqlIdentifier, Object> params, String field, Object value) {
    if (value != null) params.put(SqlIdentifier.quoted(field), value);
  }
}
