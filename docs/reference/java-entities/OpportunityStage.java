package com.acme.crm.entity.enums;

public enum OpportunityStage {
  QUALIFICATION,
  SURVEY_PROPOSAL,
  EVALUATION_TESTING,
  NEGOTIATION,
  CLOSED_WON,
  CLOSED_LOST,
  ON_HOLD,
  CANCEL;

  public static OpportunityStage from(String value) {
    if (value == null) return null;
    try {
      return OpportunityStage.valueOf(value);
    } catch (IllegalArgumentException e) {
      return null;
    }
  }
}